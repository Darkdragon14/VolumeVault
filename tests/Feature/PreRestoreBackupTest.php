<?php

namespace Tests\Feature;

use App\Actions\Backup\RunBackup;
use App\Actions\Restore\CreateRestoreRun;
use App\Actions\Restore\RunRestore;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\RestoreRun;
use App\Services\BackupDestinations\DestinationStorage;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class PreRestoreBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $storagePath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = sys_get_temp_dir().'/volumevault-pre-restore-'.uniqid();
        File::ensureDirectoryExists($this->storagePath);
        $this->app->useStoragePath($this->storagePath);
    }

    protected function tearDown(): void
    {
        if ($this->storagePath !== '') {
            File::deleteDirectory($this->storagePath);
        }

        parent::tearDown();
    }

    public function test_safety_backup_runs_before_the_volume_is_cleared_then_restore_succeeds(): void
    {
        $docker = $this->docker(volumeExists: true);
        $this->app->instance(DockerProcess::class, $docker);
        $this->app->instance(DestinationStorage::class, $this->storageThatDownloads());

        // The safety backup must run before ClearDockerVolume — assert the volume
        // is still untouched at the moment RunBackup is invoked.
        $this->app->instance(RunBackup::class, $this->fakeRunBackup(BackupRun::STATUS_SUCCESS, function () use ($docker): void {
            $this->assertFalse($docker->ranClear, 'Safety backup must run before the source volume is wiped.');
        }));

        $run = $this->restoreRun([
            'mode' => RestoreRun::MODE_INPLACE,
            'target_volume_name' => 'app_data',
            'backup_before_overwrite' => true,
        ]);

        app(RunRestore::class)->handle($run);
        $run->refresh();

        $this->assertSame(RestoreRun::STATUS_SUCCESS, $run->status);
        $this->assertTrue($docker->ranClear);
        $this->assertNotNull($run->pre_restore_backup_run_id);

        $backup = BackupRun::find($run->pre_restore_backup_run_id);
        $this->assertSame(BackupRun::TRIGGER_PRE_RESTORE, $backup->trigger);
    }

    public function test_failed_safety_backup_aborts_the_restore_without_touching_the_volume(): void
    {
        $docker = $this->docker(volumeExists: true);
        $this->app->instance(DockerProcess::class, $docker);

        // The archive is downloaded and verified first; the safety backup then
        // fails, which must abort the restore before the volume is wiped.
        $this->app->instance(DestinationStorage::class, $this->storageThatDownloads());

        $this->app->instance(RunBackup::class, $this->fakeRunBackup(BackupRun::STATUS_FAILED));

        $run = $this->restoreRun([
            'mode' => RestoreRun::MODE_INPLACE,
            'target_volume_name' => 'app_data',
            'backup_before_overwrite' => true,
        ]);

        app(RunRestore::class)->handle($run);
        $run->refresh();

        $this->assertSame(RestoreRun::STATUS_FAILED, $run->status);
        $this->assertFalse($docker->ranClear, 'The source volume must not be wiped when the safety backup fails.');
        $this->assertStringContainsString('Safety backup before overwrite failed', $run->error_message);
        // Linked even on failure so the UI can point at the failed backup run.
        $this->assertNotNull($run->pre_restore_backup_run_id);
    }

    public function test_no_safety_backup_runs_when_the_toggle_is_off(): void
    {
        $docker = $this->docker(volumeExists: true);
        $this->app->instance(DockerProcess::class, $docker);
        $this->app->instance(DestinationStorage::class, $this->storageThatDownloads());

        $runBackup = Mockery::mock(RunBackup::class);
        $runBackup->shouldNotReceive('handle');
        $this->app->instance(RunBackup::class, $runBackup);

        $run = $this->restoreRun([
            'mode' => RestoreRun::MODE_INPLACE,
            'target_volume_name' => 'app_data',
            'backup_before_overwrite' => false,
        ]);

        app(RunRestore::class)->handle($run);
        $run->refresh();

        $this->assertSame(RestoreRun::STATUS_SUCCESS, $run->status);
        $this->assertTrue($docker->ranClear);
        $this->assertNull($run->pre_restore_backup_run_id);
    }

    public function test_create_restore_run_forces_the_flag_off_outside_in_place_modes(): void
    {
        $job = $this->job();

        $newVolume = app(CreateRestoreRun::class)->handle($job, [
            'selected_backup_key' => 'backup.tar.gz',
            'mode' => RestoreRun::MODE_NEW_VOLUME,
            'backup_before_overwrite' => true,
        ]);

        $this->assertFalse($newVolume->backup_before_overwrite);

        $inPlace = app(CreateRestoreRun::class)->handle($job, [
            'selected_backup_key' => 'backup.tar.gz',
            'mode' => RestoreRun::MODE_INPLACE,
            'backup_before_overwrite' => true,
            'confirmation_text' => 'app_data',
        ]);

        $this->assertTrue($inPlace->backup_before_overwrite);
    }

    private function fakeRunBackup(string $resultStatus, ?callable $onHandle = null): RunBackup
    {
        $mock = Mockery::mock(RunBackup::class);
        $mock->shouldReceive('handle')->andReturnUsing(function (BackupRun $run) use ($resultStatus, $onHandle): void {
            if ($onHandle) {
                $onHandle();
            }

            $run->forceFill([
                'status' => $resultStatus,
                'error_message' => $resultStatus === BackupRun::STATUS_FAILED ? 'disk full' : null,
                'backup_key' => $resultStatus === BackupRun::STATUS_SUCCESS ? 'safety-backup.tar.gz' : null,
            ])->save();
        });

        return $mock;
    }

    private function job(): BackupJob
    {
        $destination = BackupDestination::create([
            'name' => 'Local',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['archive_path' => sys_get_temp_dir()],
        ]);

        return BackupJob::create([
            'name' => 'Job',
            'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);
    }

    private function restoreRun(array $overrides = []): RestoreRun
    {
        $job = $this->job();

        return RestoreRun::create(array_merge([
            'backup_job_id' => $job->id,
            'backup_destination_id' => $job->backup_destination_id,
            'selected_backup_key' => 'backup.tar.gz',
            'source_volume_name' => 'app_data',
            'target_volume_name' => 'app_data_restored',
            'mode' => RestoreRun::MODE_NEW_VOLUME,
            'status' => RestoreRun::STATUS_QUEUED,
        ], $overrides));
    }

    private function storageThatDownloads(): DestinationStorage
    {
        $storage = Mockery::mock(DestinationStorage::class);
        $storage->shouldReceive('download')
            ->andReturnUsing(function (BackupDestination $destination, string $key, string $targetPath): void {
                File::ensureDirectoryExists(dirname($targetPath));
                File::put($targetPath, 'archive');
            });

        return $storage;
    }

    private function docker(bool $volumeExists, array $containers = []): DockerProcess
    {
        return new class($volumeExists, $containers) extends DockerProcess
        {
            public bool $ranClear = false;

            public bool $ranRestore = false;

            public function __construct(private readonly bool $volumeExists, private readonly array $containers) {}

            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                $verb = $command[1] ?? null;

                if ($verb === 'volume') {
                    if (($command[2] ?? null) === 'inspect') {
                        return $this->volumeExists
                            ? new DockerProcessResult($command, 0, '[{"Name":"app_data"}]', '')
                            : new DockerProcessResult($command, 1, '', 'no such volume');
                    }

                    return new DockerProcessResult($command, 0, '', '');
                }

                if ($verb === 'ps') {
                    $lines = array_map(
                        fn (string $id) => json_encode(['ID' => $id, 'Names' => $id.'-name', 'State' => 'running']),
                        $this->containers,
                    );

                    return new DockerProcessResult($command, 0, implode("\n", $lines), '');
                }

                if ($verb === 'run' && in_array('find', $command, true)) {
                    $this->ranClear = true;
                }

                return new DockerProcessResult($command, 0, '', '');
            }

            public function runWithInputFile(array $command, string $inputPath, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                $this->ranRestore = true;

                return new DockerProcessResult($command, 0, 'restore complete', '');
            }
        };
    }
}
