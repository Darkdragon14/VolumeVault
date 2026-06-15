<?php

namespace Tests\Feature;

use App\Actions\Restore\RunRestore;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\NotificationChannel;
use App\Models\RestoreRun;
use App\Services\BackupDestinations\DestinationStorage;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use App\Services\Notifications\SendShoutrrrNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RestoreNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_started_notification_only_reaches_info_channels(): void
    {
        $run = $this->restoreRun(['status' => RestoreRun::STATUS_RUNNING]);
        $this->attachChannels($run->job, [NotificationChannel::LEVEL_ERROR, NotificationChannel::LEVEL_INFO]);

        $docker = Mockery::mock(DockerProcess::class);
        $docker->shouldReceive('run')->once()->andReturn(new DockerProcessResult([], 0, 'ok', ''));
        $this->app->instance(DockerProcess::class, $docker);

        app(SendShoutrrrNotification::class)->sendRestoreRun($run);
    }

    public function test_success_notification_only_reaches_info_channels(): void
    {
        $run = $this->restoreRun(['status' => RestoreRun::STATUS_SUCCESS, 'duration_seconds' => 5]);
        $this->attachChannels($run->job, [NotificationChannel::LEVEL_ERROR, NotificationChannel::LEVEL_INFO]);

        $docker = Mockery::mock(DockerProcess::class);
        $docker->shouldReceive('run')->once()->andReturn(new DockerProcessResult([], 0, 'ok', ''));
        $this->app->instance(DockerProcess::class, $docker);

        app(SendShoutrrrNotification::class)->sendRestoreRun($run);
    }

    public function test_failed_notification_reaches_error_and_info_channels(): void
    {
        $run = $this->restoreRun(['status' => RestoreRun::STATUS_FAILED, 'error_message' => 'Boom']);
        $this->attachChannels($run->job, [NotificationChannel::LEVEL_ERROR, NotificationChannel::LEVEL_INFO]);

        $docker = Mockery::mock(DockerProcess::class);
        $docker->shouldReceive('run')->twice()->andReturn(new DockerProcessResult([], 0, 'ok', ''));
        $this->app->instance(DockerProcess::class, $docker);

        app(SendShoutrrrNotification::class)->sendRestoreRun($run);
    }

    public function test_no_notifications_when_job_notifications_disabled(): void
    {
        $run = $this->restoreRun(['status' => RestoreRun::STATUS_FAILED, 'error_message' => 'Boom']);
        $run->job->forceFill(['notifications_enabled' => false])->save();
        $this->attachChannels($run->job, [NotificationChannel::LEVEL_INFO]);

        $docker = Mockery::mock(DockerProcess::class);
        $docker->shouldNotReceive('run');
        $this->app->instance(DockerProcess::class, $docker);

        app(SendShoutrrrNotification::class)->sendRestoreRun($run);
    }

    public function test_notification_failure_does_not_interrupt_restore(): void
    {
        $storagePath = sys_get_temp_dir().'/volumevault-restore-notify-'.uniqid();
        File::ensureDirectoryExists($storagePath);
        $this->app->useStoragePath($storagePath);

        // A throwing notifier must be swallowed so the restore still reaches its
        // terminal state.
        $notifier = Mockery::mock(SendShoutrrrNotification::class);
        $notifier->shouldReceive('sendRestoreRun')->andThrow(new RuntimeException('notify down'));
        $this->app->instance(SendShoutrrrNotification::class, $notifier);

        $this->app->instance(DockerProcess::class, $this->successfulDocker());
        $this->app->instance(DestinationStorage::class, $this->storageThatDownloads());

        $run = $this->restoreRun(['mode' => RestoreRun::MODE_INPLACE, 'target_volume_name' => 'app_data']);

        app(RunRestore::class)->handle($run);
        $run->refresh();

        $this->assertSame(RestoreRun::STATUS_SUCCESS, $run->status);

        File::deleteDirectory($storagePath);
    }

    private function attachChannels(BackupJob $job, array $levels): void
    {
        foreach ($levels as $level) {
            $channel = NotificationChannel::create([
                'name' => 'Restore '.$level,
                'service' => NotificationChannel::SERVICE_ADVANCED,
                'url' => 'ntfy://ntfy.sh/restore-'.$level,
                'notification_level' => $level,
            ]);
            $job->notificationChannels()->attach($channel);
        }
    }

    private function restoreRun(array $overrides = []): RestoreRun
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
            'name' => 'Job',
            'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);

        return RestoreRun::create(array_merge([
            'backup_job_id' => $job->id,
            'backup_destination_id' => $destination->id,
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

    private function successfulDocker(): DockerProcess
    {
        return new class extends DockerProcess
        {
            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                if (($command[1] ?? null) === 'volume' && ($command[2] ?? null) === 'inspect') {
                    return new DockerProcessResult($command, 0, '[{"Name":"app_data"}]', '');
                }

                return new DockerProcessResult($command, 0, '', '');
            }

            public function runWithInputFile(array $command, string $inputPath, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                return new DockerProcessResult($command, 0, 'restore complete', '');
            }
        };
    }
}
