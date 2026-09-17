<?php

namespace Tests\Feature;

use App\Actions\Backup\ApplyPendingDockerLabelReconciliation;
use App\Actions\Backup\ParseDockerLabelBackupDefinitions;
use App\Actions\Backup\ReconcileDockerLabelBackupJobs;
use App\Actions\Backup\SelectAuthoritativeDockerLabelBackupContainers;
use App\Actions\Backup\WithDockerLabelMutationLocks;
use App\Actions\Docker\ListDockerLabelBackupContainers;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerLabelBackupSetting;
use App\Models\DockerVolume;
use App\Models\NotificationChannel;
use App\Services\Scheduling\BackupScheduleCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DockerLabelReferenceNameRevalidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconciliation_retries_when_an_explicit_destination_is_renamed_before_locking(): void
    {
        $defaultDestination = $this->destination('Default');
        $labeledDestination = $this->destination('Labeled');
        $this->settings($defaultDestination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $list = Mockery::mock(ListDockerLabelBackupContainers::class);
        $list->shouldReceive('handle')->twice()->andReturn([$this->container([
            'destination' => 'Labeled',
        ])]);
        $locks = new class($labeledDestination) extends WithDockerLabelMutationLocks
        {
            private bool $renamed = false;

            public function __construct(private readonly BackupDestination $destination) {}

            public function handle(array $destinationIds, callable $callback, array $volumeNames = [], array $notificationChannelIds = [], array $explicitJobIds = []): mixed
            {
                if (! $this->renamed) {
                    $this->renamed = true;
                    $this->destination->update(['name' => 'Renamed']);
                }

                return parent::handle($destinationIds, $callback, $volumeNames, $notificationChannelIds, $explicitJobIds);
            }
        };

        $result = $this->reconcile($list, $locks);

        $this->assertSame(1, $result['conflicts']);
        $this->assertSame(0, BackupJob::count());
    }

    public function test_reconciliation_retries_when_an_explicit_notification_channel_is_renamed_before_locking(): void
    {
        $destination = $this->destination('Default');
        $channel = $this->channel('Alerts');
        $this->settings($destination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $list = Mockery::mock(ListDockerLabelBackupContainers::class);
        $list->shouldReceive('handle')->twice()->andReturn([$this->container([
            'notification-channels' => 'Alerts',
        ])]);
        $locks = new class($channel) extends WithDockerLabelMutationLocks
        {
            private bool $renamed = false;

            public function __construct(private readonly NotificationChannel $channel) {}

            public function handle(array $destinationIds, callable $callback, array $volumeNames = [], array $notificationChannelIds = [], array $explicitJobIds = []): mixed
            {
                if (! $this->renamed) {
                    $this->renamed = true;
                    $this->channel->update(['name' => 'Renamed']);
                }

                return parent::handle($destinationIds, $callback, $volumeNames, $notificationChannelIds, $explicitJobIds);
            }
        };

        $result = $this->reconcile($list, $locks);

        $this->assertSame(1, $result['conflicts']);
        $this->assertSame(0, BackupJob::count());
    }

    public function test_reconciliation_rejects_a_destination_name_that_becomes_duplicated_before_locking(): void
    {
        $defaultDestination = $this->destination('Default');
        $this->destination('Labeled');
        $this->settings($defaultDestination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $list = Mockery::mock(ListDockerLabelBackupContainers::class);
        $list->shouldReceive('handle')->twice()->andReturn([$this->container(['destination' => 'Labeled'])]);
        $locks = new class extends WithDockerLabelMutationLocks
        {
            private bool $duplicated = false;

            public function handle(array $destinationIds, callable $callback, array $volumeNames = [], array $notificationChannelIds = [], array $explicitJobIds = []): mixed
            {
                if (! $this->duplicated) {
                    $this->duplicated = true;
                    BackupDestination::query()->create([
                        'name' => 'Labeled',
                        'provider' => BackupDestination::PROVIDER_LOCAL,
                        'bucket' => 'local',
                        'access_key_id' => '',
                        'secret_access_key' => '',
                        'settings' => ['archive_path' => sys_get_temp_dir()],
                    ]);
                }

                return parent::handle($destinationIds, $callback, $volumeNames, $notificationChannelIds, $explicitJobIds);
            }
        };

        $result = $this->reconcile($list, $locks);

        $this->assertSame(1, $result['conflicts']);
        $this->assertSame(0, BackupJob::count());
    }

    public function test_reconciliation_rejects_a_notification_channel_name_that_becomes_duplicated_before_locking(): void
    {
        $destination = $this->destination('Default');
        $this->channel('Alerts');
        $this->settings($destination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $list = Mockery::mock(ListDockerLabelBackupContainers::class);
        $list->shouldReceive('handle')->twice()->andReturn([$this->container(['notification-channels' => 'Alerts'])]);
        $locks = new class extends WithDockerLabelMutationLocks
        {
            private bool $duplicated = false;

            public function handle(array $destinationIds, callable $callback, array $volumeNames = [], array $notificationChannelIds = [], array $explicitJobIds = []): mixed
            {
                if (! $this->duplicated) {
                    $this->duplicated = true;
                    NotificationChannel::query()->create([
                        'name' => 'Alerts',
                        'service' => NotificationChannel::SERVICE_ADVANCED,
                        'url' => 'ntfy://ntfy.sh/duplicate',
                        'notification_level' => NotificationChannel::LEVEL_ERROR,
                    ]);
                }

                return parent::handle($destinationIds, $callback, $volumeNames, $notificationChannelIds, $explicitJobIds);
            }
        };

        $result = $this->reconcile($list, $locks);

        $this->assertSame(1, $result['conflicts']);
        $this->assertSame(0, BackupJob::count());
    }

    public function test_stale_explicit_names_disable_a_pending_application(): void
    {
        $defaultDestination = $this->destination('Default');
        $labeledDestination = $this->destination('Labeled');
        $channel = $this->channel('Alerts');
        $this->settings($defaultDestination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $job = $this->managedJob($defaultDestination, BackupJob::STATUS_RUNNING);
        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_RUNNING,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'started_at' => now(),
        ]);
        $list = Mockery::mock(ListDockerLabelBackupContainers::class);
        $list->shouldReceive('handle')->once()->andReturn([$this->container([
            'destination' => 'Labeled',
            'notification-channels' => 'Alerts',
        ])]);

        $this->reconcile($list, app(WithDockerLabelMutationLocks::class));

        $pending = $job->refresh()->pending_label_reconciliation;
        $this->assertSame(['id' => $labeledDestination->id, 'name' => 'Labeled'], $pending['expected_destination']);
        $this->assertSame([['id' => $channel->id, 'name' => 'Alerts']], $pending['expected_notification_channels']);

        $channel->update(['name' => 'Renamed channel']);
        $run->update(['status' => BackupRun::STATUS_SUCCESS, 'finished_at' => now()]);
        $job->update(['status' => BackupJob::STATUS_ACTIVE]);

        $this->assertTrue(app(ApplyPendingDockerLabelReconciliation::class)->handle($job));
        $this->assertSame(BackupJob::STATUS_ERROR, $job->refresh()->status);
        $this->assertStringContainsString('notification channel', $job->label_reconciliation_error);
        $this->assertNull($job->pending_label_reconciliation);
    }

    public function test_default_references_remain_id_based_when_names_change(): void
    {
        $destination = $this->destination('Default');
        $channel = $this->channel('Alerts');
        $settings = $this->settings($destination);
        $settings->update(['defaults' => [
            ...$settings->resolvedDefaults(),
            'notification_channel_ids' => [$channel->id],
        ]]);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $job = $this->managedJob($destination, BackupJob::STATUS_RUNNING);
        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_RUNNING,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'started_at' => now(),
        ]);
        $list = Mockery::mock(ListDockerLabelBackupContainers::class);
        $list->shouldReceive('handle')->once()->andReturn([$this->container(['retention-count' => '8'])]);

        $this->reconcile($list, app(WithDockerLabelMutationLocks::class));

        $pending = $job->refresh()->pending_label_reconciliation;
        $this->assertNull($pending['expected_destination']);
        $this->assertSame([], $pending['expected_notification_channels']);

        $destination->update(['name' => 'Renamed destination']);
        $channel->update(['name' => 'Renamed channel']);
        $run->update(['status' => BackupRun::STATUS_SUCCESS, 'finished_at' => now()]);
        $job->update(['status' => BackupJob::STATUS_ACTIVE]);

        $this->assertTrue(app(ApplyPendingDockerLabelReconciliation::class)->handle($job));
        $this->assertSame(BackupJob::STATUS_ACTIVE, $job->refresh()->status);
        $this->assertSame(8, $job->retention_count);
    }

    private function reconcile(ListDockerLabelBackupContainers $list, WithDockerLabelMutationLocks $locks): array
    {
        return (new ReconcileDockerLabelBackupJobs(
            $list,
            app(ParseDockerLabelBackupDefinitions::class),
            app(SelectAuthoritativeDockerLabelBackupContainers::class),
            app(BackupScheduleCalculator::class),
            app(ApplyPendingDockerLabelReconciliation::class),
            $locks,
        ))->handle();
    }

    private function settings(BackupDestination $destination): DockerLabelBackupSetting
    {
        $settings = DockerLabelBackupSetting::current();
        $settings->update([
            'enabled' => true,
            'backup_destination_id' => $destination->id,
            'defaults' => DockerLabelBackupSetting::defaultValues(),
        ]);

        return $settings;
    }

    private function destination(string $name): BackupDestination
    {
        return BackupDestination::create([
            'name' => $name,
            'provider' => BackupDestination::PROVIDER_AWS_S3,
            'bucket' => 'backups',
            'access_key_id' => 'access',
            'secret_access_key' => 'secret',
        ]);
    }

    private function channel(string $name): NotificationChannel
    {
        return NotificationChannel::create([
            'name' => $name,
            'service' => NotificationChannel::SERVICE_ADVANCED,
            'url' => 'ntfy://ntfy.sh/alerts',
            'notification_level' => NotificationChannel::LEVEL_INFO,
        ]);
    }

    private function managedJob(BackupDestination $destination, string $status): BackupJob
    {
        return BackupJob::create([
            'name' => 'database',
            'volume_name' => 'project_database',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => $status,
            'next_run_at' => now()->addHour(),
            'configuration_source' => BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL,
            'configuration_key' => hash('sha256', 'docker-label:project/db:database'),
        ]);
    }

    private function container(array $fields): array
    {
        $labels = [
            'com.docker.compose.project' => 'project',
            'com.docker.compose.service' => 'db',
            'dev.darkdragon14.volumevault.enable' => 'true',
            'dev.darkdragon14.volumevault.backup.database.volume' => 'project_database',
        ];

        foreach ($fields as $field => $value) {
            $labels['dev.darkdragon14.volumevault.backup.database.'.$field] = $value;
        }

        return [
            'id' => 'container-id',
            'name' => 'project-db-1',
            'running' => true,
            'labels' => $labels,
            'mounts' => [[
                'name' => 'project_database',
                'destination' => '/var/lib/postgresql/data',
            ]],
        ];
    }
}
