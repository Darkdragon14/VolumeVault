<?php

namespace Tests\Feature;

use App\Actions\Backup\MarkMissingVolumeJobs;
use App\Actions\Backup\ReconcileDockerLabelBackupJobs;
use App\Actions\Backup\WithDockerLabelMutationLocks;
use App\Actions\Docker\ListDockerLabelBackupContainers;
use App\Actions\Docker\ListDockerVolumes;
use App\Actions\Docker\SyncDockerVolumes;
use App\Jobs\SyncDockerVolumesJob;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerLabelBackupSetting;
use App\Models\DockerVolume;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class MissingVolumeDetectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_referencing_missing_volume_is_marked_error(): void
    {
        $job = BackupJob::create([
            'name' => 'Nightly',
            'volume_name' => 'missing_volume',
            'backup_destination_id' => $this->destination()->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);

        app(MarkMissingVolumeJobs::class)->handle(['missing_volume']);

        $job->refresh();

        $this->assertSame(BackupJob::STATUS_ERROR, $job->status);
        $this->assertSame('Docker volume not found: missing_volume', $job->last_error);
        $this->assertSame('Docker volume not found: missing_volume', $job->pause_reason);
    }

    public function test_rediscovered_volume_is_not_marked_missing_from_a_stale_name_list(): void
    {
        DockerVolume::create(['name' => 'rediscovered_volume', 'exists' => true]);
        $job = BackupJob::create([
            'name' => 'Nightly',
            'volume_name' => 'rediscovered_volume',
            'backup_destination_id' => $this->destination()->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);

        $affected = app(MarkMissingVolumeJobs::class)->handle(['rediscovered_volume']);

        $this->assertSame(0, $affected);
        $this->assertSame(BackupJob::STATUS_ACTIVE, $job->refresh()->status);
        $this->assertNull($job->last_error);
    }

    public function test_paused_job_referencing_missing_volume_stays_paused(): void
    {
        $job = BackupJob::create([
            'name' => 'Nightly',
            'volume_name' => 'missing_volume',
            'backup_destination_id' => $this->destination()->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_PAUSED,
            'pause_reason' => 'Paused manually.',
        ]);

        app(MarkMissingVolumeJobs::class)->handle(['missing_volume']);

        $job->refresh();

        $this->assertSame(BackupJob::STATUS_PAUSED, $job->status);
        $this->assertSame('Docker volume not found: missing_volume', $job->last_error);
        $this->assertNotNull($job->last_error_at);
        $this->assertSame('Paused manually.', $job->pause_reason);
    }

    public function test_sync_removes_missing_volume_without_jobs(): void
    {
        DockerVolume::create(['name' => 'orphaned_volume', 'exists' => true]);

        $result = $this->syncDockerVolumes([]);

        $this->assertSame(0, $result['marked_missing']);
        $this->assertSame(1, $result['removed']);
        $this->assertDatabaseMissing('docker_volumes', ['name' => 'orphaned_volume']);
    }

    public function test_sync_keeps_missing_volume_referenced_by_job(): void
    {
        DockerVolume::create(['name' => 'job_volume', 'exists' => true]);

        $job = BackupJob::create([
            'name' => 'Nightly',
            'volume_name' => 'job_volume',
            'backup_destination_id' => $this->destination()->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);

        $result = $this->syncDockerVolumes([]);

        $this->assertSame(1, $result['marked_missing']);
        $this->assertSame(0, $result['removed']);
        $this->assertDatabaseHas('docker_volumes', ['name' => 'job_volume', 'exists' => false]);
        $this->assertSame(BackupJob::STATUS_ERROR, $job->refresh()->status);
    }

    public function test_full_sync_preserves_the_specific_missing_volume_error_for_a_running_managed_job(): void
    {
        $destination = $this->destination();
        DockerLabelBackupSetting::current()->update([
            'enabled' => true,
            'backup_destination_id' => $destination->id,
            'defaults' => DockerLabelBackupSetting::defaultValues(),
        ]);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $job = BackupJob::create([
            'name' => 'database',
            'volume_name' => 'project_database',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_RUNNING,
            'configuration_source' => BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL,
            'configuration_key' => hash('sha256', 'docker-label:project/db:database'),
            'label_origin' => [
                'owner_type' => 'compose',
                'project' => 'project',
                'service' => 'db',
                'container' => 'project-db-1',
                'definition_name' => 'database',
            ],
        ]);
        BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_RUNNING,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'started_at' => now(),
        ]);
        $this->mock(ListDockerLabelBackupContainers::class)
            ->shouldReceive('handle')
            ->once()
            ->andReturn([[
                'id' => 'container-id',
                'name' => 'project-db-1',
                'running' => true,
                'labels' => [
                    'com.docker.compose.project' => 'project',
                    'com.docker.compose.service' => 'db',
                    'dev.darkdragon14.volumevault.enable' => 'true',
                    'dev.darkdragon14.volumevault.backup.database.volume' => 'project_database',
                ],
                'mounts' => [[
                    'name' => 'project_database',
                    'destination' => '/var/lib/postgresql/data',
                ]],
            ]]);

        $this->syncDockerVolumes([]);

        $job->refresh();
        $this->assertSame(BackupJob::STATUS_RUNNING, $job->status);
        $this->assertSame('Docker volume not found: project_database', $job->label_reconciliation_error);
        $this->assertSame('Docker volume not found: project_database', $job->pending_label_reconciliation['message']);
    }

    public function test_later_sync_marks_a_still_missing_volume_after_its_job_stops_running(): void
    {
        DockerVolume::create(['name' => 'delayed_missing', 'exists' => true]);
        $job = BackupJob::create([
            'name' => 'Delayed',
            'volume_name' => 'delayed_missing',
            'backup_destination_id' => $this->destination()->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_RUNNING,
        ]);

        $this->syncDockerVolumes([]);
        $this->assertSame(BackupJob::STATUS_RUNNING, $job->fresh()->status);

        $job->update(['status' => BackupJob::STATUS_ACTIVE]);
        $result = $this->syncDockerVolumes([]);

        $this->assertSame(1, $result['affected_jobs']);
        $job->refresh();
        $this->assertSame(BackupJob::STATUS_ERROR, $job->status);
        $this->assertSame('Docker volume not found: delayed_missing', $job->last_error);
    }

    public function test_missing_volume_error_survives_when_the_running_label_definition_disappears(): void
    {
        $destination = $this->destination();
        DockerLabelBackupSetting::current()->update([
            'enabled' => true,
            'backup_destination_id' => $destination->id,
            'defaults' => DockerLabelBackupSetting::defaultValues(),
        ]);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $job = BackupJob::create([
            'name' => 'database',
            'volume_name' => 'project_database',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_RUNNING,
            'configuration_source' => BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL,
            'configuration_key' => hash('sha256', 'docker-label:project/db:database'),
        ]);
        BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_RUNNING,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'started_at' => now(),
        ]);
        $this->mock(ListDockerLabelBackupContainers::class)
            ->shouldReceive('handle')
            ->once()
            ->andReturn([]);

        $this->syncDockerVolumes([]);

        $job->refresh();
        $this->assertSame(BackupJob::STATUS_RUNNING, $job->status);
        $this->assertSame('Docker volume not found: project_database', $job->label_reconciliation_error);
        $this->assertSame('Docker volume not found: project_database', $job->pending_label_reconciliation['message']);
    }

    public function test_missing_current_volume_does_not_discard_a_pending_move_to_an_existing_volume(): void
    {
        $destination = $this->destination();
        DockerVolume::create(['name' => 'replacement_volume', 'exists' => true]);
        $pending = [
            'action' => 'apply',
            'payload' => [
                'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
                'volume_name' => 'replacement_volume',
                'backup_destination_id' => $destination->id,
            ],
            'notification_channel_ids' => [],
        ];
        $job = BackupJob::create([
            'name' => 'Pending move',
            'volume_name' => 'missing_volume',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'status' => BackupJob::STATUS_RUNNING,
            'configuration_source' => BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL,
            'configuration_key' => hash('sha256', 'pending-move'),
            'pending_label_reconciliation' => $pending,
        ]);

        $affected = app(MarkMissingVolumeJobs::class)->handle(['missing_volume']);

        $this->assertSame(0, $affected);
        $this->assertSame($pending, $job->refresh()->pending_label_reconciliation);
        $this->assertNull($job->label_reconciliation_error);
    }

    public function test_sync_rechecks_job_references_before_deleting_an_unseen_volume(): void
    {
        $destination = $this->destination();
        DockerVolume::create(['name' => 'newly_referenced', 'exists' => true]);
        $locks = new class($destination) extends WithDockerLabelMutationLocks
        {
            public function __construct(private readonly BackupDestination $destination) {}

            public function handle(
                array $destinationIds,
                callable $callback,
                array $volumeNames = [],
                array $notificationChannelIds = [],
                array $explicitJobIds = [],
            ): mixed {
                BackupJob::create([
                    'name' => 'Concurrent job',
                    'volume_name' => 'newly_referenced',
                    'backup_destination_id' => $this->destination->id,
                    'schedule_type' => BackupJob::SCHEDULE_DAILY,
                    'schedule_config' => ['time' => '02:00'],
                    'status' => BackupJob::STATUS_ACTIVE,
                ]);

                return parent::handle($destinationIds, $callback, $volumeNames, $notificationChannelIds, $explicitJobIds);
            }
        };
        $this->app->instance(WithDockerLabelMutationLocks::class, $locks);

        $result = $this->syncDockerVolumes([]);

        $this->assertSame(0, $result['removed']);
        $this->assertDatabaseHas('docker_volumes', ['name' => 'newly_referenced', 'exists' => false]);
    }

    public function test_sync_removes_stale_missing_volume_after_last_job_is_deleted(): void
    {
        DockerVolume::create(['name' => 'stale_missing_volume', 'exists' => false]);

        $result = $this->syncDockerVolumes([]);

        $this->assertSame(1, $result['removed']);
        $this->assertDatabaseMissing('docker_volumes', ['name' => 'stale_missing_volume']);
    }

    public function test_manual_web_and_api_sync_share_the_scheduled_sync_lock(): void
    {
        $lockPath = storage_path('framework/'.SyncDockerVolumes::LOCK_FILENAME);
        File::ensureDirectoryExists(dirname($lockPath));
        $lock = fopen($lockPath, 'c');
        $this->assertIsResource($lock);
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        $admin = User::factory()->admin()->create();

        try {
            $this->travel(1)->day();

            $this->actingAs($admin)
                ->post(route('volumes.sync'))
                ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'already in progress'));

            $token = $admin->createToken('sync', ['read', 'write'])->plainTextToken;
            $this->withToken($token)
                ->postJson('/api/v1/volumes/sync')
                ->assertStatus(422)
                ->assertJsonPath('error', 'Docker volume synchronization is already in progress.');

            try {
                app(SyncDockerVolumesJob::class)->handle(app(SyncDockerVolumes::class));
                $this->fail('The scheduled synchronization should contend on the shared lock.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Docker volume synchronization is already in progress.', $exception->getMessage());
            }
        } finally {
            $this->travelBack();
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function test_sync_job_timeout_and_queue_retry_are_finite_and_ordered(): void
    {
        $job = new SyncDockerVolumesJob;

        $this->assertSame(SyncDockerVolumesJob::TIMEOUT_SECONDS, $job->timeout);

        foreach (['database', 'redis', 'beanstalkd'] as $connection) {
            $this->assertGreaterThan(
                $job->timeout,
                config("queue.connections.{$connection}.retry_after"),
                "The {$connection} queue retry_after must exceed the sync job timeout.",
            );
        }
    }

    public function test_sync_lock_is_released_after_an_exception(): void
    {
        $failingList = Mockery::mock(ListDockerVolumes::class);
        $failingList->shouldReceive('handle')->once()->andThrow(new RuntimeException('Docker unavailable.'));
        $action = new SyncDockerVolumes($failingList, app(MarkMissingVolumeJobs::class), app(ReconcileDockerLabelBackupJobs::class), app(WithDockerLabelMutationLocks::class));

        try {
            $action->handle();
            $this->fail('The Docker failure should be propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Docker unavailable.', $exception->getMessage());
        }

        $result = $this->syncDockerVolumes([]);
        $this->assertSame(0, $result['found']);
    }

    private function destination(): BackupDestination
    {
        return BackupDestination::create([
            'name' => 'S3',
            'provider' => BackupDestination::PROVIDER_AWS_S3,
            'bucket' => 'backups',
            'access_key_id' => 'access',
            'secret_access_key' => 'secret',
        ]);
    }

    private function syncDockerVolumes(array $volumes): array
    {
        $listDockerVolumes = Mockery::mock(ListDockerVolumes::class);
        $listDockerVolumes->shouldReceive('handle')->once()->andReturn($volumes);

        return (new SyncDockerVolumes($listDockerVolumes, app(MarkMissingVolumeJobs::class), app(ReconcileDockerLabelBackupJobs::class), app(WithDockerLabelMutationLocks::class)))->handle();
    }
}
