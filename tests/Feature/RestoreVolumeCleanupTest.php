<?php

namespace Tests\Feature;

use App\Actions\Restore\RunRestore;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\RestoreRun;
use App\Services\BackupDestinations\DestinationStorage;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class RestoreVolumeCleanupTest extends TestCase
{
    use RefreshDatabase;

    private string $storagePath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = sys_get_temp_dir().'/volumevault-restore-cleanup-'.uniqid();
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

    public function test_failed_restore_removes_partially_created_target_volume(): void
    {
        $docker = $this->dockerProcess(restoreSucceeds: false);
        $this->app->instance(DockerProcess::class, $docker);
        $this->app->instance(DestinationStorage::class, $this->storageThatDownloads());

        $run = $this->restoreRun();

        app(RunRestore::class)->handle($run);
        $run->refresh();

        $this->assertSame(RestoreRun::STATUS_FAILED, $run->status);
        $this->assertContains(
            ['volume', 'rm', $run->target_volume_name],
            $docker->volumeCommands,
            'The partially-created target volume should be removed so a retry can start clean.'
        );
        $this->assertStringContainsString('Removed partially-created target volume', $run->logs);
    }

    public function test_successful_restore_keeps_the_target_volume(): void
    {
        $docker = $this->dockerProcess(restoreSucceeds: true);
        $this->app->instance(DockerProcess::class, $docker);
        $this->app->instance(DestinationStorage::class, $this->storageThatDownloads());

        $run = $this->restoreRun();

        app(RunRestore::class)->handle($run);
        $run->refresh();

        $this->assertSame(RestoreRun::STATUS_SUCCESS, $run->status);
        $this->assertNotContains(
            ['volume', 'rm', $run->target_volume_name],
            $docker->volumeCommands,
            'A successful restore must not delete the volume it just populated.'
        );
    }

    private function restoreRun(): RestoreRun
    {
        $destination = BackupDestination::create([
            'name' => 'Local',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['archive_path' => sys_get_temp_dir()],
        ]);

        $job = BackupJob::create([
            'name' => 'Local app backup',
            'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);

        return RestoreRun::create([
            'backup_job_id' => $job->id,
            'backup_destination_id' => $destination->id,
            'selected_backup_key' => 'backup.tar.gz',
            'source_volume_name' => 'app_data',
            'target_volume_name' => 'app_data_restored_20260608_120000',
            'mode' => RestoreRun::MODE_NEW_VOLUME,
            'status' => RestoreRun::STATUS_QUEUED,
        ]);
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

    private function dockerProcess(bool $restoreSucceeds): DockerProcess
    {
        return new class($restoreSucceeds) extends DockerProcess
        {
            /** @var array<int, array<int, string>> */
            public array $volumeCommands = [];

            public function __construct(private readonly bool $restoreSucceeds) {}

            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                if (($command[1] ?? null) === 'volume') {
                    $this->volumeCommands[] = array_slice($command, 1);

                    // The target volume does not exist yet, so `inspect` fails;
                    // `create` and `rm` both succeed.
                    if (($command[2] ?? null) === 'inspect') {
                        return new DockerProcessResult($command, 1, '', 'no such volume');
                    }

                    return new DockerProcessResult($command, 0, '', '');
                }

                if (($command[1] ?? null) === 'run') {
                    return $this->restoreSucceeds
                        ? new DockerProcessResult($command, 0, 'restore complete', '')
                        : new DockerProcessResult($command, 1, '', 'tar: extraction failed');
                }

                return new DockerProcessResult($command, 0, '', '');
            }

            public function runWithInputFile(array $command, string $inputPath, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                return $this->run($command, $timeout, $environment);
            }
        };
    }
}
