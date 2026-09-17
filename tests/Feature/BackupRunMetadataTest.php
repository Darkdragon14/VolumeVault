<?php

namespace Tests\Feature;

use App\Actions\Backup\CreateBackupRunRecord;
use App\Actions\Backup\RunBackup;
use App\Actions\Destinations\DestinationMutationBlocked;
use App\Actions\Destinations\MutateDestination;
use App\Actions\Restore\ResolveRestoreDestination;
use App\Actions\Runs\ProcessRunFinalization;
use App\Jobs\ProcessRunFinalizationJob as ProcessBackupRunFinalizationJob;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerVolume;
use App\Models\RunFinalization as BackupRunFinalization;
use App\Services\BackupDestinations\ListBackupObjects;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BackupRunMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The local destination is now fail-closed: allow the temp dir these
        // tests write archives to, plus its canonical form (the run-time
        // re-check resolves realpath, and /tmp & /var are symlinks on macOS).
        config(['volumevault.host_path_allowlist' => array_unique([
            sys_get_temp_dir(),
            realpath(sys_get_temp_dir()) ?: sys_get_temp_dir(),
        ])]);
    }

    public function test_successful_backup_records_archive_key_and_size_when_available(): void
    {
        $archivePath = sys_get_temp_dir().'/volumevault-backup-metadata-success';
        File::deleteDirectory($archivePath);
        File::ensureDirectoryExists($archivePath);

        $this->app->instance(DockerProcess::class, $this->dockerProcess(createArchive: true));

        $run = $this->backupRun($archivePath);
        app(RunBackup::class)->handle($run);
        $run->refresh();

        $this->assertSame(BackupRun::STATUS_SUCCESS, $run->status);
        $this->assertSame('volumevault-app_data-run-'.$run->id.'.tar.gz', $run->backup_key);
        $this->assertSame(1536, $run->backup_size_bytes);
        $this->assertDatabaseHas('run_finalizations', [
            'backup_run_id' => $run->id,
            'type' => BackupRunFinalization::TYPE_ARCHIVE_METADATA,
            'status' => BackupRunFinalization::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseMissing('run_finalizations', [
            'backup_run_id' => $run->id,
            'type' => BackupRunFinalization::TYPE_FINISHED_NOTIFICATION,
        ]);
    }

    public function test_successful_backup_records_custom_archive_key_when_template_is_configured(): void
    {
        $archivePath = sys_get_temp_dir().'/volumevault-backup-metadata-custom';
        File::deleteDirectory($archivePath);
        File::ensureDirectoryExists($archivePath);

        $this->app->instance(DockerProcess::class, $this->dockerProcess(createArchive: true));

        $run = $this->backupRun($archivePath, ['backup_filename_template' => '{name}-{id}']);
        app(RunBackup::class)->handle($run);
        $run->refresh();

        $this->assertSame(BackupRun::STATUS_SUCCESS, $run->status);
        $this->assertSame('Local_app_backup-'.$run->id.'.tar.gz', $run->backup_key);
        $this->assertSame(1536, $run->backup_size_bytes);
    }

    public function test_dropbox_finalization_cannot_attach_a_replacement_file_id_after_delete_and_recreate(): void
    {
        Queue::fake([ProcessBackupRunFinalizationJob::class]);
        Exceptions::fake();
        $this->app->instance(DockerProcess::class, $this->dockerProcess(createArchive: false));
        $run = $this->dropboxRun();
        $run->update(['status' => BackupRun::STATUS_QUEUED]);

        app(RunBackup::class)->handle($run);
        $run->refresh();
        $this->assertSame(BackupRun::STATUS_SUCCESS, $run->status);
        $this->assertTrue($run->archive_metadata_pending);

        // The uploaded object was deleted and a different object now occupies its path.
        Http::fake([
            'https://api.dropboxapi.com/oauth2/token' => Http::response(['access_token' => 'token']),
            'https://api.dropboxapi.com/2/files/list_folder' => Http::response([
                'entries' => [[
                    '.tag' => 'file',
                    'id' => 'id:replacement-file',
                    'path_display' => '/backups/'.$run->backup_filename,
                    'size' => 1536,
                    'server_modified' => '2026-09-03T12:00:00Z',
                ]],
                'has_more' => false,
            ]),
        ]);
        $finalization = $run->finalizations()->where('type', BackupRunFinalization::TYPE_ARCHIVE_METADATA)->firstOrFail();
        app(ProcessRunFinalization::class)->handle($finalization->id);

        $this->assertSame(BackupRunFinalization::STATUS_FAILED, $finalization->refresh()->status);
        $this->assertStringContainsString('upload did not capture a stable file ID', $finalization->last_error);
        $this->assertNull($run->refresh()->backup_key);
        $this->assertNull($run->backup_size_bytes);
        $this->assertSame(BackupRun::STATUS_SUCCESS, $run->status);
        Http::assertNothingSent();

        $this->expectException(ValidationException::class);
        app(ResolveRestoreDestination::class)->handle($run->job, $run->id);
    }

    #[DataProvider('unverifiableDropboxKeys')]
    public function test_dropbox_metadata_never_upgrades_a_filename_to_a_stable_id(?string $key): void
    {
        Http::fake();
        $run = $this->dropboxRun();
        $run->update(['backup_key' => $key]);

        app(RunBackup::class)->recordArchiveMetadata($run->id);

        $this->assertSame($key, $run->refresh()->backup_key);
        $this->assertNull($run->backup_size_bytes);
        $this->assertTrue(ListBackupObjects::isRunUnverifiable($run->destinationForRun(), $run));
        Http::assertNothingSent();

        $this->expectException(ValidationException::class);
        app(ListBackupObjects::class)->handleForRun($run->destinationForRun(), $run);
    }

    public static function unverifiableDropboxKeys(): array
    {
        return ['missing ID' => [null], 'historical path' => ['/backups/backup.tar.gz']];
    }

    public function test_dropbox_metadata_preserves_an_existing_exact_id_and_size(): void
    {
        Http::fake();
        $run = $this->dropboxRun();
        $metadata = ['backup_key' => 'id:original-upload', 'backup_size_bytes' => 1536];
        $run->update($metadata);

        app(RunBackup::class)->recordArchiveMetadata($run->id);

        $this->assertSame($metadata, app(RunBackup::class)->detectArchiveMetadata($run->id));
        $this->assertSame($metadata['backup_key'], $run->refresh()->backup_key);
        $this->assertSame($metadata['backup_size_bytes'], $run->backup_size_bytes);
        Http::assertNothingSent();
    }

    #[DataProvider('unverifiableDropboxKeys')]
    public function test_dropbox_synchronous_metadata_detection_rejects_unproven_identity(?string $key): void
    {
        Http::fake();
        $run = $this->dropboxRun();
        $run->update(['backup_key' => $key]);

        try {
            app(RunBackup::class)->detectArchiveMetadata($run->id);
            $this->fail('Dropbox identity must not be inferred from a filename.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('upload did not capture a stable file ID', $exception->getMessage());
        }

        $this->assertSame($key, $run->refresh()->backup_key);
        Http::assertNothingSent();
    }

    public function test_run_execution_options_do_not_change_when_the_job_is_edited_after_queueing(): void
    {
        $archivePath = sys_get_temp_dir().'/volumevault-backup-options-snapshot';
        File::ensureDirectoryExists($archivePath);
        $initialOptions = [
            'retention_days' => 7,
            'retention_count' => 3,
            'backup_exclude_regexp' => '\\.cache$',
            'backup_filter_mode' => BackupJob::FILTER_MODE_INCLUDE,
            'backup_include_paths' => '/data /config',
            'stop_containers_before_backup' => true,
            'stop_container_names' => ['app', 'worker'],
        ];
        $unsnapshotted = $this->backupRun($archivePath, $initialOptions);
        $run = app(CreateBackupRunRecord::class)->handle($unsnapshotted->job, [
            'status' => BackupRun::STATUS_QUEUED,
            'trigger' => BackupRun::TRIGGER_MANUAL,
        ]);
        $run->job->update([
            'retention_days' => 30,
            'retention_count' => 10,
            'backup_exclude_regexp' => null,
            'backup_filter_mode' => BackupJob::FILTER_MODE_EXCLUDE,
            'backup_include_paths' => null,
            'stop_containers_before_backup' => false,
            'stop_container_names' => [],
        ]);

        $run = $run->fresh();
        $this->assertSame($initialOptions, $run->execution_options_snapshot);
        $executionJob = $run->executionJob();

        foreach ($initialOptions as $field => $value) {
            $this->assertSame($value, $executionJob->{$field});
        }
    }

    public function test_missing_archive_metadata_does_not_fail_successful_backup(): void
    {
        $archivePath = sys_get_temp_dir().'/volumevault-backup-metadata-missing';
        File::deleteDirectory($archivePath);

        $this->app->instance(DockerProcess::class, $this->dockerProcess(createArchive: false));

        $run = $this->backupRun($archivePath);
        app(RunBackup::class)->handle($run);
        $run->refresh();

        $this->assertSame(BackupRun::STATUS_SUCCESS, $run->status);
        $this->assertNull($run->backup_key);
        $this->assertNull($run->backup_size_bytes);
        $this->assertDatabaseHas('run_finalizations', [
            'backup_run_id' => $run->id,
            'type' => BackupRunFinalization::TYPE_ARCHIVE_METADATA,
            'status' => BackupRunFinalization::STATUS_FAILED,
        ]);
        $this->assertNotNull($run->finalizations()->where('type', BackupRunFinalization::TYPE_ARCHIVE_METADATA)->firstOrFail()->last_error);
    }

    public function test_metadata_dispatch_failure_leaves_durable_work_for_the_sweep(): void
    {
        $archivePath = sys_get_temp_dir().'/volumevault-backup-metadata-dispatch-failure';
        File::deleteDirectory($archivePath);
        File::ensureDirectoryExists($archivePath);
        Exceptions::fake();
        Queue::shouldReceive('connection')->once()->andThrow(new RuntimeException('Queue unavailable.'));
        $this->app->instance(DockerProcess::class, $this->dockerProcess(createArchive: true));

        $run = $this->backupRun($archivePath);
        app(RunBackup::class)->handle($run);
        $run->refresh();

        $this->assertSame(BackupRun::STATUS_SUCCESS, $run->status);
        $this->assertTrue($run->archive_metadata_pending);
        $this->assertTrue($run->job->destination->hasRunInProgress());
        $this->assertDatabaseHas('run_finalizations', [
            'backup_run_id' => $run->id,
            'type' => BackupRunFinalization::TYPE_ARCHIVE_METADATA,
            'status' => BackupRunFinalization::STATUS_PENDING,
        ]);
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Queue unavailable.');
    }

    public function test_pre_restore_safety_backup_does_not_unpause_or_touch_the_job(): void
    {
        $archivePath = sys_get_temp_dir().'/volumevault-backup-metadata-pre-restore';
        File::deleteDirectory($archivePath);
        File::ensureDirectoryExists($archivePath);

        $this->app->instance(DockerProcess::class, $this->dockerProcess(createArchive: true));

        // A manually paused job whose volume is being restored in place with the
        // safety-backup option on.
        $run = $this->backupRun($archivePath, [
            'status' => BackupJob::STATUS_PAUSED,
            'pause_reason' => 'maintenance window',
        ]);
        $run->forceFill(['trigger' => BackupRun::TRIGGER_PRE_RESTORE])->save();

        app(RunBackup::class)->handle($run);
        $run->refresh();
        $job = $run->job->refresh();

        // The safety backup itself succeeds, but the job stays exactly as paused.
        $this->assertSame(BackupRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(BackupJob::STATUS_PAUSED, $job->status);
        $this->assertSame('maintenance window', $job->pause_reason);
        $this->assertNull($job->last_success_at);
        $this->assertDatabaseMissing('run_finalizations', [
            'backup_run_id' => $run->id,
            'type' => BackupRunFinalization::TYPE_FINISHED_NOTIFICATION,
        ]);
    }

    public function test_terminal_backup_is_not_rerun(): void
    {
        $archivePath = sys_get_temp_dir().'/volumevault-backup-metadata-terminal';
        File::deleteDirectory($archivePath);
        File::ensureDirectoryExists($archivePath);

        $this->app->instance(DockerProcess::class, $this->dockerProcess(createArchive: true));

        // Reconciliation already failed this run; a late-delivered queued job must
        // not resurrect it. The atomic claim matches zero rows and bails.
        $run = $this->backupRun($archivePath);
        $run->forceFill(['status' => BackupRun::STATUS_FAILED, 'finished_at' => now()->subHour()])->save();

        app(RunBackup::class)->handle($run);
        $run->refresh();

        $this->assertSame(BackupRun::STATUS_FAILED, $run->status);
        $this->assertNull($run->backup_key);
    }

    public function test_metadata_uses_snapshots_after_job_mutation_and_blocks_destination_mutation_while_pending(): void
    {
        $archivePath = sys_get_temp_dir().'/volumevault-backup-metadata-snapshot';
        $replacementPath = sys_get_temp_dir().'/volumevault-backup-metadata-replacement';
        File::deleteDirectory($archivePath);
        File::deleteDirectory($replacementPath);
        File::ensureDirectoryExists($archivePath);
        File::ensureDirectoryExists($replacementPath);
        Queue::fake([ProcessBackupRunFinalizationJob::class]);

        $this->app->instance(DockerProcess::class, $this->dockerProcess(createArchive: true));
        $legacyRun = $this->backupRun($archivePath);
        $job = $legacyRun->job;
        $legacyRun->delete();
        $run = app(CreateBackupRunRecord::class)->handle($job, [
            'status' => BackupRun::STATUS_QUEUED,
            'trigger' => BackupRun::TRIGGER_MANUAL,
        ]);

        app(RunBackup::class)->handle($run);
        $run->refresh();
        $this->assertTrue($run->archive_metadata_pending);

        $this->expectException(DestinationMutationBlocked::class);
        app(MutateDestination::class)->update($job->destination, [
            'is_active' => true,
            'settings' => ['archive_path' => $replacementPath, 'archive_mount_source' => $replacementPath],
        ]);
    }

    public function test_metadata_lookup_uses_original_destination_after_job_destination_changes(): void
    {
        $archivePath = sys_get_temp_dir().'/volumevault-backup-metadata-original';
        $replacementPath = sys_get_temp_dir().'/volumevault-backup-metadata-new';
        File::deleteDirectory($archivePath);
        File::deleteDirectory($replacementPath);
        File::ensureDirectoryExists($archivePath);
        File::ensureDirectoryExists($replacementPath);
        Queue::fake([ProcessBackupRunFinalizationJob::class]);

        $this->app->instance(DockerProcess::class, $this->dockerProcess(createArchive: true));
        $legacyRun = $this->backupRun($archivePath);
        $job = $legacyRun->job;
        $legacyRun->delete();
        $run = app(CreateBackupRunRecord::class)->handle($job, [
            'status' => BackupRun::STATUS_QUEUED,
            'trigger' => BackupRun::TRIGGER_MANUAL,
        ]);
        app(RunBackup::class)->handle($run);

        $replacement = BackupDestination::create([
            'name' => 'Replacement',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'replacement',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['archive_path' => $replacementPath, 'archive_mount_source' => $replacementPath],
        ]);
        $job->update([
            'backup_destination_id' => $replacement->id,
            'volume_name' => 'renamed_volume',
            'backup_filename_template' => 'changed-{id}',
        ]);

        app(RunBackup::class)->recordArchiveMetadata($run->id);
        $run->refresh();

        $this->assertSame('volumevault-app_data-run-'.$run->id.'.tar.gz', $run->backup_key);
        $this->assertSame(1536, $run->backup_size_bytes);
        $this->assertFalse($run->archive_metadata_pending);
    }

    private function dropboxRun(): BackupRun
    {
        $run = $this->backupRun(sys_get_temp_dir());
        $run->job->destination->update([
            'provider' => BackupDestination::PROVIDER_DROPBOX,
            'settings' => ['remote_path' => '/backups'],
            'secrets' => ['app_key' => 'app', 'app_secret' => 'secret', 'refresh_token' => 'refresh'],
        ]);
        $job = $run->job;
        $run->delete();

        return app(CreateBackupRunRecord::class)->handle($job->fresh('destination'), [
            'status' => BackupRun::STATUS_SUCCESS,
            'trigger' => BackupRun::TRIGGER_MANUAL,
            'backup_filename' => 'backup.tar.gz',
        ]);
    }

    private function backupRun(string $archivePath, array $jobOverrides = []): BackupRun
    {
        DockerVolume::create(['name' => 'app_data', 'exists' => true]);
        $destination = BackupDestination::create([
            'name' => 'Local',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['archive_path' => $archivePath, 'archive_mount_source' => $archivePath],
        ]);
        $job = BackupJob::create(array_merge([
            'name' => 'Local app backup',
            'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ], $jobOverrides));

        return BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_QUEUED,
            'trigger' => BackupRun::TRIGGER_MANUAL,
        ]);
    }

    private function dockerProcess(bool $createArchive): DockerProcess
    {
        return new class($createArchive) extends DockerProcess
        {
            public function __construct(private readonly bool $createArchive) {}

            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                if (($command[0] ?? null) === 'docker' && ($command[1] ?? null) === 'volume' && ($command[2] ?? null) === 'inspect') {
                    return new DockerProcessResult($command, 0, json_encode([[
                        'Name' => 'app_data',
                        'Driver' => 'local',
                        'Mountpoint' => '/var/lib/docker/volumes/app_data/_data',
                        'Labels' => [],
                        'Options' => [],
                    ]], JSON_THROW_ON_ERROR), '');
                }

                if (($command[0] ?? null) === 'docker' && ($command[1] ?? null) === 'run') {
                    if ($this->createArchive && isset($environment['BACKUP_ARCHIVE'], $environment['BACKUP_FILENAME'])) {
                        File::put($environment['BACKUP_ARCHIVE'].'/'.$environment['BACKUP_FILENAME'], str_repeat('x', 1536));
                    }

                    return new DockerProcessResult($command, 0, 'backup complete', '');
                }

                return new DockerProcessResult($command, 0, '', '');
            }
        };
    }
}
