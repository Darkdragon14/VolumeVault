<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\NotificationChannel;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use App\Services\Notifications\ResolveNotificationChannels;
use App\Services\Notifications\SendShoutrrrNotification;
use App\Services\Notifications\ShoutrrrUrlBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class NotificationChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_url_is_encrypted_and_hidden_from_frontend(): void
    {
        $channel = NotificationChannel::create([
            'name' => 'Discord',
            'service' => NotificationChannel::SERVICE_ADVANCED,
            'url' => 'discord://secret-token@123456789',
            'notification_level' => NotificationChannel::LEVEL_ERROR,
            'scope' => NotificationChannel::SCOPE_ALL,
        ]);

        $this->assertNotSame('discord://secret-token@123456789', $channel->getRawOriginal('url'));
        $this->assertSame('discord://secret-token@123456789', $channel->url);

        $payload = $channel->load('backupJobs')->safeForFrontend();

        $this->assertArrayNotHasKey('url', $payload);
        $this->assertStringNotContainsString('secret-token', json_encode($payload));
        $this->assertSame('********', $payload['masked_url']);
    }

    public function test_builder_creates_guided_discord_url_from_webhook(): void
    {
        $url = app(ShoutrrrUrlBuilder::class)->build(NotificationChannel::SERVICE_DISCORD, [
            'webhook_url' => 'https://discord.com/api/webhooks/123456789/token-value',
            'username' => 'VolumeVault',
        ]);

        $this->assertSame('discord://token-value@123456789?username=VolumeVault&splitLines=No', $url);
    }

    public function test_resolver_returns_global_and_specific_channels_for_job(): void
    {
        [$job, $otherJob] = $this->createJobs();

        $global = NotificationChannel::create([
            'name' => 'Global',
            'service' => NotificationChannel::SERVICE_ADVANCED,
            'url' => 'ntfy://ntfy.sh/global',
            'notification_level' => NotificationChannel::LEVEL_ERROR,
            'scope' => NotificationChannel::SCOPE_ALL,
        ]);

        $specific = NotificationChannel::create([
            'name' => 'Specific',
            'service' => NotificationChannel::SERVICE_ADVANCED,
            'url' => 'ntfy://ntfy.sh/specific',
            'notification_level' => NotificationChannel::LEVEL_ERROR,
            'scope' => NotificationChannel::SCOPE_SPECIFIC,
        ]);
        $specific->backupJobs()->attach($job);

        $ignored = NotificationChannel::create([
            'name' => 'Ignored',
            'service' => NotificationChannel::SERVICE_ADVANCED,
            'url' => 'ntfy://ntfy.sh/ignored',
            'notification_level' => NotificationChannel::LEVEL_ERROR,
            'scope' => NotificationChannel::SCOPE_SPECIFIC,
        ]);
        $ignored->backupJobs()->attach($otherJob);

        $resolved = app(ResolveNotificationChannels::class)->forJob($job)->pluck('id')->all();

        $this->assertContains($global->id, $resolved);
        $this->assertContains($specific->id, $resolved);
        $this->assertNotContains($ignored->id, $resolved);
    }

    public function test_success_notifications_only_send_to_info_channels(): void
    {
        [$job] = $this->createJobs();
        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_SUCCESS,
            'trigger' => BackupRun::TRIGGER_MANUAL,
            'duration_seconds' => 3,
        ]);

        NotificationChannel::create([
            'name' => 'Errors',
            'service' => NotificationChannel::SERVICE_ADVANCED,
            'url' => 'ntfy://ntfy.sh/errors',
            'notification_level' => NotificationChannel::LEVEL_ERROR,
            'scope' => NotificationChannel::SCOPE_ALL,
        ]);

        NotificationChannel::create([
            'name' => 'All',
            'service' => NotificationChannel::SERVICE_ADVANCED,
            'url' => 'ntfy://ntfy.sh/all',
            'notification_level' => NotificationChannel::LEVEL_INFO,
            'scope' => NotificationChannel::SCOPE_ALL,
        ]);

        $dockerProcess = Mockery::mock(DockerProcess::class);
        $dockerProcess->shouldReceive('run')->once()->andReturn(new DockerProcessResult([], 0, 'ok', ''));
        $this->app->instance(DockerProcess::class, $dockerProcess);

        app(SendShoutrrrNotification::class)->sendBackupRunFinished($run);
    }

    public function test_failed_notifications_send_to_error_and_info_channels(): void
    {
        [$job] = $this->createJobs();
        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_FAILED,
            'trigger' => BackupRun::TRIGGER_MANUAL,
            'error_message' => 'Boom',
        ]);

        foreach ([NotificationChannel::LEVEL_ERROR, NotificationChannel::LEVEL_INFO] as $level) {
            NotificationChannel::create([
                'name' => $level,
                'service' => NotificationChannel::SERVICE_ADVANCED,
                'url' => 'ntfy://ntfy.sh/'.$level,
                'notification_level' => $level,
                'scope' => NotificationChannel::SCOPE_ALL,
            ]);
        }

        $dockerProcess = Mockery::mock(DockerProcess::class);
        $dockerProcess->shouldReceive('run')->twice()->andReturn(new DockerProcessResult([], 0, 'ok', ''));
        $this->app->instance(DockerProcess::class, $dockerProcess);

        app(SendShoutrrrNotification::class)->sendBackupRunFinished($run);
    }

    public function test_discord_notifications_disable_line_splitting_and_use_title_flag(): void
    {
        [$job] = $this->createJobs();
        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_SUCCESS,
            'trigger' => BackupRun::TRIGGER_MANUAL,
            'duration_seconds' => 3,
        ]);

        NotificationChannel::create([
            'name' => 'Discord',
            'service' => NotificationChannel::SERVICE_DISCORD,
            'url' => 'discord://token@123?username=VolumeVault',
            'notification_level' => NotificationChannel::LEVEL_INFO,
            'scope' => NotificationChannel::SCOPE_ALL,
        ]);

        $dockerProcess = Mockery::mock(DockerProcess::class);
        $dockerProcess->shouldReceive('run')
            ->once()
            ->with(
                Mockery::on(fn (array $command) => in_array('--title', $command, true)
                    && in_array('VolumeVault backup succeeded', $command, true)
                    && in_array('--message', $command, true)
                    && ! in_array("VolumeVault backup succeeded\n\nJob: Nightly", $command, true)),
                60,
                Mockery::on(fn (array $environment) => $environment['SHOUTRRR_URL'] === 'discord://token@123?username=VolumeVault&splitLines=No'),
            )
            ->andReturn(new DockerProcessResult([], 0, 'ok', ''));
        $this->app->instance(DockerProcess::class, $dockerProcess);

        app(SendShoutrrrNotification::class)->sendBackupRunFinished($run);
    }

    public function test_backup_notifications_can_use_custom_templates(): void
    {
        [$job] = $this->createJobs();
        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_FAILED,
            'trigger' => BackupRun::TRIGGER_MANUAL,
            'error_message' => 'Boom',
            'duration_seconds' => 9,
        ]);

        NotificationChannel::create([
            'name' => 'Ntfy',
            'service' => NotificationChannel::SERVICE_ADVANCED,
            'url' => 'ntfy://ntfy.sh/all',
            'notification_level' => NotificationChannel::LEVEL_ERROR,
            'scope' => NotificationChannel::SCOPE_ALL,
            'title_template' => 'Backup {{ status }}: {{ job }}',
            'body_template' => "{{ volume }} to {{ destination }} in {{ duration }}\n{{ error }}",
        ]);

        $dockerProcess = Mockery::mock(DockerProcess::class);
        $dockerProcess->shouldReceive('run')
            ->once()
            ->with(
                Mockery::on(fn (array $command) => in_array('Backup failed: Nightly', $command, true)
                    && in_array("app_data to S3 in 9s\nBoom", $command, true)),
                60,
                Mockery::any(),
            )
            ->andReturn(new DockerProcessResult([], 0, 'ok', ''));
        $this->app->instance(DockerProcess::class, $dockerProcess);

        app(SendShoutrrrNotification::class)->sendBackupRunFinished($run);
    }

    private function createJobs(): array
    {
        $destination = BackupDestination::create([
            'name' => 'S3',
            'provider' => BackupDestination::PROVIDER_AWS_S3,
            'bucket' => 'backups',
            'access_key_id' => 'access',
            'secret_access_key' => 'secret',
        ]);

        return [
            BackupJob::create([
                'name' => 'Nightly',
                'volume_name' => 'app_data',
                'backup_destination_id' => $destination->id,
                'schedule_type' => BackupJob::SCHEDULE_DAILY,
                'schedule_config' => ['time' => '02:00'],
                'cron_expression' => '0 2 * * *',
                'status' => BackupJob::STATUS_ACTIVE,
            ]),
            BackupJob::create([
                'name' => 'Weekly',
                'volume_name' => 'other_data',
                'backup_destination_id' => $destination->id,
                'schedule_type' => BackupJob::SCHEDULE_WEEKLY,
                'schedule_config' => ['dayOfWeek' => 'sunday', 'time' => '03:00'],
                'cron_expression' => '0 3 * * 0',
                'status' => BackupJob::STATUS_ACTIVE,
            ]),
        ];
    }
}
