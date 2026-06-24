<?php

namespace Tests\Feature;

use App\Actions\Backup\RunBackup;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerVolume;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
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
        $this->assertStringContainsString('Backup archive size could not be detected.', $run->logs);
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
