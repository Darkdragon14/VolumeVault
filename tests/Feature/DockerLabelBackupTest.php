<?php

namespace Tests\Feature;

use App\Actions\Backup\ApplyPendingDockerLabelReconciliation;
use App\Actions\Backup\CreateBackupRun;
use App\Actions\Backup\ParseDockerLabelBackupDefinitions;
use App\Actions\Backup\ReconcileDockerLabelBackupJobs;
use App\Actions\Backup\SelectAuthoritativeDockerLabelBackupContainers;
use App\Actions\Backup\WithDockerLabelMutationLocks;
use App\Actions\Docker\ListDockerLabelBackupContainers;
use App\Actions\Notifications\DeleteNotificationChannel;
use App\Jobs\DispatchDueBackupJobsJob;
use App\Jobs\RunBackupJob;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerLabelBackupSetting;
use App\Models\DockerVolume;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Services\Scheduling\BackupScheduleCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class DockerLabelBackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_named_job_from_container_labels_and_defaults(): void
    {
        $destination = $this->destination();
        $this->settings($destination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);

        $result = $this->reconcile([[...$this->container(),
            'labels' => [
                'com.docker.compose.project' => 'project',
                'com.docker.compose.service' => 'db',
                'dev.darkdragon14.volumevault.enable' => 'true',
                'dev.darkdragon14.volumevault.backup.database.mount' => '/var/lib/postgresql/data',
                'dev.darkdragon14.volumevault.backup.database.time' => '04:15',
            ],
        ]]);

        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas('backup_jobs', [
            'name' => 'database',
            'volume_name' => 'project_database',
            'configuration_source' => BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL,
            'configuration_key' => hash('sha256', 'docker-label:project/db:database'),
            'backup_destination_id' => $destination->id,
        ]);
        $this->assertSame(['time' => '04:15'], BackupJob::firstOrFail()->schedule_config);
        $this->assertSame(
            $this->composeOrigin('project', 'db', 'database', 'project-db-1'),
            BackupJob::firstOrFail()->label_origin,
        );
    }

    public function test_single_backup_shortcuts_select_a_volume_or_mount(): void
    {
        $destination = $this->destination();
        $this->settings($destination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);

        foreach (['volume' => 'project_database', 'mount' => '/var/lib/postgresql/data'] as $selector => $value) {
            BackupJob::query()->delete();
            $result = $this->reconcile([[...$this->container(),
                'labels' => [
                    'dev.darkdragon14.volumevault.enable' => 'true',
                    'dev.darkdragon14.volumevault.backup.'.$selector => $value,
                ],
            ]]);

            $this->assertSame(1, $result['created']);
            $this->assertSame('project_database', BackupJob::firstOrFail()->volume_name);
        }
    }

    public function test_reconciliation_updates_structured_origin_metadata(): void
    {
        $destination = $this->destination();
        $this->settings($destination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $this->reconcile([$this->labeledContainer()]);
        $container = $this->labeledContainer();
        $container['id'] = 'replacement';
        $container['name'] = 'project-db-2';

        $this->reconcile([$container]);

        $this->assertSame(
            $this->composeOrigin('project', 'db', 'database', 'project-db-2'),
            BackupJob::firstOrFail()->label_origin,
        );
    }

    public function test_a_manual_job_is_not_modified_or_duplicated(): void
    {
        $destination = $this->destination();
        $this->settings($destination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        BackupJob::create([
            'name' => 'Manual database',
            'volume_name' => 'project_database',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '01:00'],
            'cron_expression' => '0 1 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);

        $result = $this->reconcile([[...$this->container(),
            'labels' => [
                'dev.darkdragon14.volumevault.enable' => 'true',
                'dev.darkdragon14.volumevault.backup.database.volume' => 'project_database',
            ],
        ]]);

        $this->assertSame(1, $result['conflicts']);
        $this->assertSame(1, BackupJob::count());
        $this->assertSame(BackupJob::CONFIGURATION_SOURCE_MANUAL, BackupJob::firstOrFail()->configuration_source);
    }

    public function test_matching_compose_replicas_create_one_job(): void
    {
        $destination = $this->destination();
        $this->settings($destination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $labels = [
            'com.docker.compose.project' => 'project',
            'com.docker.compose.service' => 'db',
            'dev.darkdragon14.volumevault.enable' => 'true',
            'dev.darkdragon14.volumevault.backup.database.volume' => 'project_database',
        ];

        $result = $this->reconcile([
            [...$this->container(), 'id' => 'replica-1', 'name' => 'project-db-1', 'labels' => $labels],
            [...$this->container(), 'id' => 'replica-2', 'name' => 'project-db-2', 'labels' => $labels],
        ]);

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, BackupJob::count());
    }

    public function test_project_only_compose_identity_is_indeterminate_and_preserves_an_existing_compose_job(): void
    {
        $destination = $this->destination();
        $this->settings($destination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $job = $this->managedJob($destination);
        $container = $this->labeledContainer();
        unset($container['labels']['com.docker.compose.service']);

        $result = $this->reconcile([$container]);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['conflicts']);
        $this->assertSame(BackupJob::STATUS_ACTIVE, $job->refresh()->status);
        $this->assertNull($job->label_reconciliation_error);
        $this->assertSame(1, BackupJob::count());
    }

    public function test_service_only_compose_identity_is_indeterminate_and_preserves_an_existing_compose_job(): void
    {
        $destination = $this->destination();
        $this->settings($destination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $job = $this->managedJob($destination);
        $container = $this->labeledContainer();
        unset($container['labels']['com.docker.compose.project']);

        $result = $this->reconcile([$container]);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['conflicts']);
        $this->assertSame(BackupJob::STATUS_ACTIVE, $job->refresh()->status);
        $this->assertNull($job->label_reconciliation_error);
        $this->assertSame(1, BackupJob::count());
    }

    public function test_partial_compose_observations_preserve_only_matching_origins_and_union_independently(): void
    {
        $destination = $this->destination();
        $this->settings($destination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        DockerVolume::create(['name' => 'other_cache', 'exists' => true]);
        DockerVolume::create(['name' => 'stale_files', 'exists' => true]);
        $projectJob = $this->managedJob($destination);
        $serviceJob = $this->managedJob($destination, [
            'name' => 'cache',
            'volume_name' => 'other_cache',
            'configuration_key' => hash('sha256', 'docker-label:other/cache:cache'),
            'label_origin' => $this->composeOrigin('other', 'cache', 'cache', 'other-cache-1'),
        ]);
        $staleJob = $this->managedJob($destination, [
            'name' => 'files',
            'volume_name' => 'stale_files',
            'configuration_key' => hash('sha256', 'docker-label:stale/files:files'),
            'label_origin' => $this->composeOrigin('stale', 'files', 'files', 'stale-files-1'),
        ]);
        $projectOnlyMalformed = $this->labeledContainer();
        unset($projectOnlyMalformed['labels']['com.docker.compose.service']);
        $projectOnlyMalformed['labels']['dev.darkdragon14.volumevault.backup.database.unknown'] = 'value';
        $serviceOnly = $this->labeledContainer();
        $serviceOnly['id'] = 'other-cache-1';
        $serviceOnly['name'] = 'other-cache-1';
        $serviceOnly['labels'] = [
            'com.docker.compose.service' => 'cache',
            'dev.darkdragon14.volumevault.enable' => 'true',
            'dev.darkdragon14.volumevault.backup.cache.volume' => 'other_cache',
        ];

        $result = $this->reconcile([$projectOnlyMalformed, $serviceOnly]);

        $this->assertSame(2, $result['conflicts']);
        $this->assertNull($projectJob->refresh()->label_reconciliation_error);
        $this->assertNull($serviceJob->refresh()->label_reconciliation_error);
        $this->assertSame(BackupJob::STATUS_ERROR, $staleJob->refresh()->status);
        $this->assertNotNull($staleJob->label_reconciliation_error);
    }

    public function test_partial_observation_does_not_preserve_legacy_rows_without_origin_metadata(): void
    {
        $destination = $this->destination();
        $this->settings($destination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $job = $this->managedJob($destination, ['label_origin' => null]);
        $container = $this->labeledContainer();
        unset($container['labels']['com.docker.compose.service']);

        $this->reconcile([$container]);

        $this->assertSame(BackupJob::STATUS_ERROR, $job->refresh()->status);
        $this->assertNotNull($job->label_reconciliation_error);
    }

    public function test_complete_compose_replicas_remain_authoritative_when_an_incomplete_replica_is_present(): void
    {
        $destination = $this->destination();
        $this->settings($destination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $job = $this->managedJob($destination);
        $complete = $this->labeledContainer(['retention-count' => '7']);
        $incomplete = $this->labeledContainer(['retention-count' => '3']);
        $incomplete['id'] = 'incomplete';
        unset($incomplete['labels']['com.docker.compose.service']);

        $result = $this->reconcile([$complete, $incomplete]);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['conflicts']);
        $this->assertSame(1, BackupJob::count());
        $this->assertSame(7, $job->refresh()->retention_count);
        $this->assertSame(BackupJob::STATUS_ACTIVE, $job->status);
        $this->assertNull($job->label_reconciliation_error);
    }

    public function test_stopped_standalone_container_does_not_create_a_new_managed_job(): void
    {
        $destination = $this->destination();
        $this->settings($destination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $container = $this->labeledContainer();
        $container['running'] = false;
        unset(
            $container['labels']['com.docker.compose.project'],
            $container['labels']['com.docker.compose.service'],
        );

        $result = $this->reconcile([$container]);

        $this->assertSame(0, $result['created']);
        $this->assertSame(0, BackupJob::count());
    }

    public function test_stopped_standalone_container_preserves_an_existing_matching_managed_job(): void
    {
        $destination = $this->destination();
        $this->settings($destination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $job = $this->managedJob($destination, [
            'configuration_key' => hash('sha256', 'docker-label:project-db-1:database'),
        ]);
        $container = $this->labeledContainer(['retention-count' => '6']);
        $container['running'] = false;
        unset(
            $container['labels']['com.docker.compose.project'],
            $container['labels']['com.docker.compose.service'],
        );

        $result = $this->reconcile([$container]);

        $this->assertSame(0, $result['created']);
        $this->assertSame(6, $job->refresh()->retention_count);
        $this->assertSame(BackupJob::STATUS_ACTIVE, $job->status);
        $this->assertNull($job->label_reconciliation_error);
    }

    public function test_active_replicas_must_agree_on_enable_state_and_definition_names(): void
    {
        $destination = $this->destination();
        $this->settings($destination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $job = $this->managedJob($destination);
        $base = [
            'com.docker.compose.project' => 'project',
            'com.docker.compose.service' => 'db',
        ];

        foreach ([
            [[...$base, 'dev.darkdragon14.volumevault.enable' => 'true', 'dev.darkdragon14.volumevault.backup.database.volume' => 'project_database'], [...$base, 'dev.darkdragon14.volumevault.enable' => 'false']],
            [[...$base, 'dev.darkdragon14.volumevault.enable' => 'true', 'dev.darkdragon14.volumevault.backup.database.volume' => 'project_database'], [...$base, 'dev.darkdragon14.volumevault.enable' => 'true', 'dev.darkdragon14.volumevault.backup.database.volume' => 'project_database', 'dev.darkdragon14.volumevault.backup.cache.volume' => 'project_database']],
        ] as [$labelsA, $labelsB]) {
            $job->update(['status' => BackupJob::STATUS_ACTIVE, 'label_reconciliation_error' => null, 'pending_label_reconciliation' => null]);
            $result = $this->reconcile([
                [...$this->container(), 'id' => 'replica-a', 'running' => true, 'labels' => $labelsA],
                [...$this->container(), 'id' => 'replica-b', 'running' => true, 'labels' => $labelsB],
            ]);

            $this->assertGreaterThanOrEqual(1, $result['conflicts']);
            $this->assertSame(BackupJob::STATUS_ERROR, $job->refresh()->status);
        }
    }

    public function test_newest_stopped_compose_generation_is_authoritative(): void
    {
        $destination = $this->destination();
        $this->settings($destination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $job = $this->managedJob($destination);

        $old = $this->labeledContainer(['retention-count' => '3']);
        $old['id'] = 'old';
        $old['running'] = false;
        $old['created'] = '2026-08-01T00:00:00Z';
        $old['labels']['com.docker.compose.container-number'] = '1';
        $old['labels']['com.docker.compose.config-hash'] = 'old-hash';
        $current = $this->labeledContainer(['retention-count' => '7']);
        $current['id'] = 'current';
        $current['running'] = false;
        $current['created'] = '2026-08-13T00:00:00Z';
        $current['labels']['com.docker.compose.container-number'] = '1';
        $current['labels']['com.docker.compose.config-hash'] = 'new-hash';

        $result = $this->reconcile([$old, $current]);

        $this->assertSame(0, $result['conflicts']);
        $this->assertSame(7, $job->refresh()->retention_count);
    }

    public function test_newest_slot_wins_with_partial_compose_generation_metadata(): void
    {
        $destination = $this->destination();
        $this->settings($destination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $job = $this->managedJob($destination);

        $old = $this->labeledContainer(['retention-count' => '3']);
        $old = [...$old, 'id' => 'old', 'running' => false, 'created' => '2026-08-01T00:00:00Z'];
        $old['labels']['com.docker.compose.container-number'] = '1';
        $old['labels']['com.docker.compose.config-hash'] = 'old-hash';
        $current = $this->labeledContainer(['retention-count' => '9']);
        $current = [...$current, 'id' => 'current', 'running' => false, 'created' => '2026-08-13T00:00:00Z'];
        $current['labels']['com.docker.compose.container-number'] = '1';

        $this->reconcile([$old, $current]);

        $this->assertSame(9, $job->refresh()->retention_count);
    }

    public function test_newest_running_container_wins_during_same_slot_deployment_overlap(): void
    {
        $destination = $this->destination();
        $this->settings($destination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $job = $this->managedJob($destination);

        $old = $this->labeledContainer(['retention-count' => '3']);
        $old = [...$old, 'id' => 'old', 'running' => true, 'created' => '2026-08-01T00:00:00Z'];
        $old['labels']['com.docker.compose.container-number'] = '1';
        $old['labels']['com.docker.compose.config-hash'] = 'old-hash';
        $current = $this->labeledContainer(['retention-count' => '11']);
        $current = [...$current, 'id' => 'current', 'running' => true, 'created' => '2026-08-13T00:00:00Z'];
        $current['labels']['com.docker.compose.container-number'] = '1';
        $current['labels']['com.docker.compose.config-hash'] = 'new-hash';

        $result = $this->reconcile([$old, $current]);

        $this->assertSame(0, $result['conflicts']);
        $this->assertSame(11, $job->refresh()->retention_count);
    }

    public function test_a_malformed_compose_replica_disables_the_shared_job(): void
    {
        $destination = $this->destination();
        $this->settings($destination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $job = BackupJob::create([
            'name' => 'database',
            'volume_name' => 'project_database',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
            'configuration_source' => BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL,
            'configuration_key' => hash('sha256', 'docker-label:project/db:database'),
        ]);
        $baseLabels = [
            'com.docker.compose.project' => 'project',
            'com.docker.compose.service' => 'db',
            'dev.darkdragon14.volumevault.enable' => 'true',
        ];

        $result = $this->reconcile([
            [...$this->container(), 'id' => 'replica-1', 'labels' => [...$baseLabels, 'dev.darkdragon14.volumevault.backup.database.volume' => 'project_database']],
            [...$this->container(), 'id' => 'replica-2', 'labels' => [...$baseLabels, 'dev.darkdragon14.volumevault.backup.database.unknown' => 'value']],
        ]);

        $this->assertSame(1, $result['conflicts']);
        $this->assertSame(BackupJob::STATUS_ERROR, $job->refresh()->status);
        $this->assertNull($job->next_run_at);
    }

    public function test_malformed_replica_inference_distinguishes_default_and_named_volume_definitions(): void
    {
        $destination = $this->destination();
        $this->settings($destination);

        foreach ([
            'dev.darkdragon14.volumevault.backup.unknown' => 'default',
            'dev.darkdragon14.volumevault.backup.volume.unknown' => 'volume',
        ] as $malformedLabel => $expectedName) {
            BackupJob::query()->delete();
            $job = BackupJob::create([
                'name' => $expectedName,
                'volume_name' => 'project_database',
                'backup_destination_id' => $destination->id,
                'schedule_type' => BackupJob::SCHEDULE_DAILY,
                'schedule_config' => ['time' => '02:00'],
                'cron_expression' => '0 2 * * *',
                'status' => BackupJob::STATUS_ACTIVE,
                'configuration_source' => BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL,
                'configuration_key' => hash('sha256', 'docker-label:project/db:'.$expectedName),
            ]);

            $this->reconcile([[...$this->container(), 'labels' => [
                'com.docker.compose.project' => 'project',
                'com.docker.compose.service' => 'db',
                'dev.darkdragon14.volumevault.enable' => 'true',
                $malformedLabel => 'value',
            ]]]);

            $this->assertSame(BackupJob::STATUS_ERROR, $job->refresh()->status);
        }
    }

    public function test_a_retention_only_update_preserves_the_next_run(): void
    {
        $destination = $this->destination();
        $this->settings($destination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $nextRunAt = now()->addMinutes(5)->startOfSecond();
        $job = BackupJob::create([
            'name' => 'database',
            'volume_name' => 'project_database',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
            'next_run_at' => $nextRunAt,
            'configuration_source' => BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL,
            'configuration_key' => hash('sha256', 'docker-label:project/db:database'),
        ]);

        $this->reconcile([[...$this->container(), 'labels' => [
            'com.docker.compose.project' => 'project',
            'com.docker.compose.service' => 'db',
            'dev.darkdragon14.volumevault.enable' => 'true',
            'dev.darkdragon14.volumevault.backup.database.volume' => 'project_database',
            'dev.darkdragon14.volumevault.backup.database.retention-count' => '5',
        ]]]);

        $this->assertSame(5, $job->refresh()->retention_count);
        $this->assertTrue($nextRunAt->equalTo($job->next_run_at));
    }

    public function test_a_configuration_change_cancels_a_queued_run_before_applying(): void
    {
        $destination = $this->destination();
        $otherDestination = $this->destination('Other');
        $this->settings($destination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $job = $this->managedJob($destination);
        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_QUEUED,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
        ]);

        $this->reconcile([[...$this->container(), 'labels' => [
            'com.docker.compose.project' => 'project',
            'com.docker.compose.service' => 'db',
            'dev.darkdragon14.volumevault.enable' => 'true',
            'dev.darkdragon14.volumevault.backup.database.volume' => 'project_database',
            'dev.darkdragon14.volumevault.backup.database.destination' => $otherDestination->name,
            'dev.darkdragon14.volumevault.backup.database.retention-count' => '9',
        ]]]);

        $this->assertSame(BackupRun::STATUS_CANCELLED, $run->refresh()->status);
        $this->assertSame($otherDestination->id, $job->refresh()->backup_destination_id);
        $this->assertSame(9, $job->retention_count);
        $this->assertNull($job->pending_label_reconciliation);
    }

    public function test_reconciliation_requeues_a_scheduled_occurrence_after_dispatch_advanced_the_schedule(): void
    {
        $this->travelTo('2026-09-07 02:05:00');
        Queue::fake([RunBackupJob::class]);
        $destination = $this->destination();
        $this->settings($destination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $channel = $this->channel('Operations');
        $job = $this->managedJob($destination, [
            'next_run_at' => '2026-09-07 02:00:00',
        ]);

        app()->call([app(DispatchDueBackupJobsJob::class), 'handle']);

        $this->assertTrue(BackupRun::sole()->scheduled_for->equalTo(Carbon::parse('2026-09-07 02:00:00')));
        $this->assertTrue($job->refresh()->next_run_at->equalTo(Carbon::parse('2026-09-08 02:00:00')));

        $this->reconcile([$this->labeledContainer([
            'retention-count' => '9',
            'notification-channels' => $channel->name,
        ])]);

        $this->assertSame(BackupRun::STATUS_CANCELLED, BackupRun::sole()->status);
        $this->assertTrue($job->refresh()->next_run_at->equalTo(Carbon::parse('2026-09-07 02:00:00')));
        $this->assertSame(9, $job->retention_count);
        $this->assertSame([$channel->id], $job->notificationChannels()->pluck('notification_channels.id')->all());

        app()->call([app(DispatchDueBackupJobsJob::class), 'handle']);

        $this->assertSame(2, BackupRun::count());
        $replacement = BackupRun::query()->where('status', BackupRun::STATUS_QUEUED)->sole();
        $this->assertSame(BackupRun::TRIGGER_SCHEDULED, $replacement->trigger);
        $this->assertTrue($replacement->scheduled_for->equalTo(Carbon::parse('2026-09-07 02:00:00')));
        $this->assertTrue($job->refresh()->next_run_at->equalTo(Carbon::parse('2026-09-08 02:00:00')));
        Queue::assertPushed(RunBackupJob::class, 2);
    }

    public function test_run_creation_reloads_the_job_after_reconciliation_changes_it(): void
    {
        $destination = $this->destination();
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $job = $this->managedJob($destination);
        $stale = clone $job;
        $job->update([
            'status' => BackupJob::STATUS_ERROR,
            'label_reconciliation_error' => 'Definition removed.',
            'pending_label_reconciliation' => ['action' => 'disable', 'message' => 'Definition removed.'],
        ]);

        try {
            app(CreateBackupRun::class)->handle($stale, BackupRun::TRIGGER_MANUAL);
            $this->fail('A stale active model must not queue a run after reconciliation disabled it.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('job', $exception->errors());
        }

        $this->assertSame(0, BackupRun::count());
    }

    public function test_a_manual_pause_survives_disappearance_and_return(): void
    {
        $destination = $this->destination();
        $this->settings($destination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $job = $this->managedJob($destination, [
            'status' => BackupJob::STATUS_PAUSED,
            'pause_reason' => 'Maintenance window.',
        ]);
        $nextRunAt = $job->next_run_at;

        $this->reconcile([]);
        $this->assertSame(BackupJob::STATUS_PAUSED, $job->refresh()->status);
        $this->assertSame('Maintenance window.', $job->pause_reason);
        $this->assertNotNull($job->label_reconciliation_error);
        $this->assertTrue($nextRunAt->equalTo($job->next_run_at));

        $this->reconcile([[...$this->container(), 'labels' => [
            'com.docker.compose.project' => 'project',
            'com.docker.compose.service' => 'db',
            'dev.darkdragon14.volumevault.enable' => 'true',
            'dev.darkdragon14.volumevault.backup.database.volume' => 'project_database',
        ]]]);

        $this->assertSame(BackupJob::STATUS_PAUSED, $job->refresh()->status);
        $this->assertSame('Maintenance window.', $job->pause_reason);
        $this->assertNull($job->label_reconciliation_error);
        $this->assertTrue($nextRunAt->equalTo($job->next_run_at));
    }

    public function test_latest_labels_replace_stale_pending_configuration_and_notifications(): void
    {
        $destination = $this->destination();
        $otherDestination = $this->destination('Other');
        $channelA = $this->channel('Channel A');
        $channelB = $this->channel('Channel B');
        $this->settings($destination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $job = $this->managedJob($destination, ['status' => BackupJob::STATUS_RUNNING]);
        $job->notificationChannels()->sync([$channelA->id]);
        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_RUNNING,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'started_at' => now(),
        ]);

        $this->reconcile([$this->labeledContainer([
            'destination' => $otherDestination->name,
            'retention-count' => '8',
            'notification-channels' => $channelB->name,
        ])]);
        $this->assertSame($otherDestination->id, $job->refresh()->pending_label_reconciliation['payload']['backup_destination_id']);

        $this->reconcile([$this->labeledContainer([
            'destination' => $destination->name,
            'notification-channels' => $channelA->name,
        ])]);
        $pending = $job->refresh()->pending_label_reconciliation;
        $this->assertSame($destination->id, $pending['payload']['backup_destination_id']);
        $this->assertSame([$channelA->id], $pending['notification_channel_ids']);
        $this->assertNull($pending['payload']['retention_count']);

        $run->update(['status' => BackupRun::STATUS_SUCCESS, 'finished_at' => now()]);
        $job->update(['status' => BackupJob::STATUS_ACTIVE]);
        app(ApplyPendingDockerLabelReconciliation::class)->handle($job);

        $this->assertSame($destination->id, $job->refresh()->backup_destination_id);
        $this->assertNull($job->retention_count);
        $this->assertSame([$channelA->id], $job->notificationChannels()->pluck('notification_channels.id')->all());
    }

    public function test_active_job_reconciliation_replaces_only_its_notification_channels(): void
    {
        $destination = $this->destination();
        $channelX = $this->channel('Channel X');
        $channelY = $this->channel('Channel Y');
        $channelZ = $this->channel('Channel Z');
        $this->settings($destination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $job = $this->managedJob($destination);
        $job->notificationChannels()->sync([$channelX->id]);
        $otherJob = $this->managedJob($destination, [
            'name' => 'Other managed job',
            'volume_name' => 'other_volume',
            'configuration_key' => hash('sha256', 'docker-label:other/app:data'),
            'label_origin' => $this->composeOrigin('other', 'app', 'data', 'other-app-1'),
        ]);
        $otherJob->notificationChannels()->sync([$channelZ->id]);

        $this->reconcile([$this->labeledContainer([
            'notification-channels' => $channelY->name,
        ])]);

        $job->refresh();
        $this->assertSame(BackupJob::STATUS_ACTIVE, $job->status);
        $this->assertNull($job->label_reconciliation_error);
        $this->assertSame([$channelY->id], $job->notificationChannels()->pluck('notification_channels.id')->all());
    }

    public function test_stopped_label_owner_remains_authoritative_during_backup(): void
    {
        $destination = $this->destination();
        $this->settings($destination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $job = $this->managedJob($destination, ['status' => BackupJob::STATUS_RUNNING]);

        $this->reconcile([[...$this->labeledContainer(), 'running' => false]]);

        $this->assertNull($job->refresh()->label_reconciliation_error);
        $this->assertNull($job->pending_label_reconciliation);
    }

    public function test_deleted_pending_references_are_handled_without_foreign_key_errors(): void
    {
        $destination = $this->destination();
        $pendingDestination = $this->destination('Pending');
        $channel = $this->channel('Pending channel');
        $job = $this->managedJob($destination, ['status' => BackupJob::STATUS_RUNNING]);
        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_RUNNING,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'started_at' => now(),
        ]);
        $job->update(['pending_label_reconciliation' => [
            'action' => 'apply',
            'payload' => [...$job->only($job->getFillable()), 'backup_destination_id' => $pendingDestination->id],
            'next_run_at' => now()->addHour()->toIso8601String(),
            'notification_channel_ids' => [$channel->id],
        ]]);

        $pendingDestination->delete();
        app(DeleteNotificationChannel::class)->handle($channel);
        $run->update(['status' => BackupRun::STATUS_SUCCESS, 'finished_at' => now()]);
        $job->update(['status' => BackupJob::STATUS_ACTIVE]);

        $this->assertTrue(app(ApplyPendingDockerLabelReconciliation::class)->handle($job));
        $this->assertSame(BackupJob::STATUS_ERROR, $job->refresh()->status);
        $this->assertStringContainsString('destination', $job->label_reconciliation_error);
    }

    public function test_deleted_pending_channel_is_removed_before_application(): void
    {
        $destination = $this->destination();
        $channel = $this->channel('Pending channel');
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $job = $this->managedJob($destination, ['status' => BackupJob::STATUS_RUNNING]);
        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_RUNNING,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'started_at' => now(),
        ]);
        $payload = $job->only([
            'name', 'backup_job_group_id', 'source_type', 'volume_name', 'host_path',
            'backup_destination_id', 'schedule_type', 'schedule_config', 'cron_expression',
            'timezone', 'retention_days', 'retention_count', 'backup_filter_mode',
            'backup_include_paths', 'backup_exclude_regexp', 'backup_filename_template',
            'notifications_enabled', 'alert_notifications_enabled', 'use_custom_alert_settings',
            'stop_containers_before_backup', 'stop_container_names',
        ]);
        $payload['source_type'] = BackupJob::SOURCE_TYPE_DOCKER_VOLUME;
        $payload['backup_filter_mode'] = BackupJob::FILTER_MODE_EXCLUDE;
        $payload['notifications_enabled'] = true;
        $payload['alert_notifications_enabled'] = true;
        $payload['use_custom_alert_settings'] = false;
        $payload['stop_containers_before_backup'] = false;
        $payload['retention_count'] = 6;
        $job->update(['pending_label_reconciliation' => [
            'action' => 'apply',
            'payload' => $payload,
            'next_run_at' => now()->addHour()->toIso8601String(),
            'notification_channel_ids' => [$channel->id],
        ]]);

        app(DeleteNotificationChannel::class)->handle($channel);
        $run->update(['status' => BackupRun::STATUS_SUCCESS, 'finished_at' => now()]);
        $job->update(['status' => BackupJob::STATUS_ACTIVE]);

        $this->assertTrue(app(ApplyPendingDockerLabelReconciliation::class)->handle($job));
        $this->assertSame(BackupJob::STATUS_ACTIVE, $job->refresh()->status);
        $this->assertSame(6, $job->retention_count);
        $this->assertSame(0, $job->notificationChannels()->count());
        $this->assertNull($job->pending_label_reconciliation);
    }

    public function test_pending_configuration_is_disabled_when_its_locked_volume_is_missing(): void
    {
        $destination = $this->destination();
        DockerVolume::create(['name' => 'project_database', 'exists' => false]);
        $job = $this->managedJob($destination);
        $payload = $job->only([
            'name', 'backup_job_group_id', 'source_type', 'volume_name', 'host_path',
            'backup_destination_id', 'schedule_type', 'schedule_config', 'cron_expression',
            'timezone', 'retention_days', 'retention_count', 'backup_filter_mode',
            'backup_include_paths', 'backup_exclude_regexp', 'backup_filename_template',
            'notifications_enabled', 'alert_notifications_enabled', 'use_custom_alert_settings',
            'stop_containers_before_backup', 'stop_container_names',
        ]);
        $job->update(['pending_label_reconciliation' => [
            'action' => 'apply',
            'payload' => $payload,
            'next_run_at' => now()->addHour()->toIso8601String(),
            'notification_channel_ids' => [],
        ]]);

        $this->assertTrue(app(ApplyPendingDockerLabelReconciliation::class)->handle($job));
        $this->assertSame(BackupJob::STATUS_ERROR, $job->refresh()->status);
        $this->assertStringContainsString('volume', $job->label_reconciliation_error);
        $this->assertNull($job->pending_label_reconciliation);
    }

    public function test_disappearance_during_a_running_backup_is_persisted_until_completion(): void
    {
        $destination = $this->destination();
        $this->settings($destination);
        $job = $this->managedJob($destination, ['status' => BackupJob::STATUS_RUNNING]);
        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_RUNNING,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'started_at' => now(),
        ]);

        $this->reconcile([]);
        $this->assertSame(BackupJob::STATUS_RUNNING, $job->refresh()->status);
        $this->assertSame('disable', $job->pending_label_reconciliation['action']);

        $run->update(['status' => BackupRun::STATUS_SUCCESS, 'finished_at' => now()]);
        $job->update(['status' => BackupJob::STATUS_ACTIVE]);
        app(ApplyPendingDockerLabelReconciliation::class)->handle($job);

        $this->assertSame(BackupJob::STATUS_ERROR, $job->refresh()->status);
        $this->assertNull($job->next_run_at);
        $this->assertNull($job->pending_label_reconciliation);
    }

    public function test_deleting_a_default_notification_channel_does_not_break_reconciliation(): void
    {
        $destination = $this->destination();
        $channel = NotificationChannel::create([
            'name' => 'Alerts',
            'service' => NotificationChannel::SERVICE_ADVANCED,
            'url' => 'ntfy://ntfy.sh/alerts',
            'notification_level' => NotificationChannel::LEVEL_INFO,
        ]);
        $settings = $this->settings($destination);
        $settings->update(['defaults' => [...$settings->resolvedDefaults(), 'notification_channel_ids' => [$channel->id]]]);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        app(DeleteNotificationChannel::class)->handle($channel);

        $result = $this->reconcile([[...$this->container(), 'labels' => [
            'dev.darkdragon14.volumevault.enable' => 'true',
            'dev.darkdragon14.volumevault.backup.volume' => 'project_database',
        ]]]);

        $this->assertSame(1, $result['created']);
        $this->assertSame([], DockerLabelBackupSetting::current()->resolvedDefaults()['notification_channel_ids']);
        $this->assertSame(0, BackupJob::firstOrFail()->notificationChannels()->count());
    }

    public function test_notification_channels_must_use_the_atomic_deletion_action(): void
    {
        $channel = $this->channel('Protected channel');

        $this->expectException(\LogicException::class);

        $channel->delete();
    }

    public function test_settings_migration_is_reversible_and_seeds_the_lock_row(): void
    {
        $migration = require database_path('migrations/2026_08_13_000000_create_docker_label_backup_settings_table.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('docker_label_backup_settings'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('docker_label_backup_settings'));
        $this->assertDatabaseCount('docker_label_backup_settings', 1);

        (require database_path('migrations/2026_09_18_151946_scope_docker_label_backup_settings_to_hosts.php'))->up();
        $settings = DockerLabelBackupSetting::current();

        $this->assertSame(1, $settings->id);
        $this->assertSame(DockerLabelBackupSetting::defaultValues(), $settings->resolvedDefaults());
        $this->assertDatabaseCount('docker_label_backup_settings', 1);

        $settings->delete();
        $migration->up();

        $this->assertDatabaseCount('docker_label_backup_settings', 1);
        $this->assertSame(1, DockerLabelBackupSetting::current()->id);
    }

    public function test_disabling_automation_disables_existing_managed_jobs(): void
    {
        $destination = $this->destination();
        DockerLabelBackupSetting::current()->update([
            'enabled' => false,
            'backup_destination_id' => $destination->id,
            'defaults' => DockerLabelBackupSetting::defaultValues(),
        ]);
        $job = BackupJob::create([
            'name' => 'database',
            'volume_name' => 'project_database',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
            'next_run_at' => now()->addHour(),
            'configuration_source' => BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL,
            'configuration_key' => hash('sha256', 'docker-label:project/db:database'),
        ]);

        $result = $this->reconcile([]);

        $this->assertSame(1, $result['disabled']);
        $this->assertSame(BackupJob::STATUS_ERROR, $job->refresh()->status);
        $this->assertNull($job->next_run_at);
    }

    public function test_a_disappeared_definition_is_retained_and_disabled(): void
    {
        $destination = $this->destination();
        $this->settings($destination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $job = BackupJob::create([
            'name' => 'database',
            'volume_name' => 'project_database',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
            'configuration_source' => BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL,
            'configuration_key' => hash('sha256', 'docker-label:project/db:database'),
        ]);

        $result = $this->reconcile([]);

        $this->assertSame(1, $result['disabled']);
        $this->assertSame(BackupJob::STATUS_ERROR, $job->refresh()->status);
        $this->assertSame('Docker label definition is no longer active.', $job->last_error);
    }

    public function test_manual_jobs_can_be_created_by_web_and_api_after_declarations_disappear(): void
    {
        $destination = $this->destination();
        $this->settings($destination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        DockerVolume::create(['name' => 'project_logs', 'exists' => true]);
        $databaseTombstone = $this->managedJob($destination);
        $historicalRun = BackupRun::create([
            'backup_job_id' => $databaseTombstone->id,
            'status' => BackupRun::STATUS_SUCCESS,
            'trigger' => BackupRun::TRIGGER_MANUAL,
            'finished_at' => now(),
        ]);
        $logsTombstone = $this->managedJob($destination, [
            'name' => 'logs',
            'volume_name' => 'project_logs',
            'configuration_key' => hash('sha256', 'docker-label:project/app:logs'),
            'label_origin' => $this->composeOrigin('project', 'app', 'logs', 'project-app-1'),
        ]);
        $this->reconcile([]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('backup-jobs.store'), $this->manualPayload($destination, 'project_database'))
            ->assertSessionHasNoErrors();

        $token = $admin->createToken('vv', ['read', 'write'])->plainTextToken;
        $this->withToken($token)
            ->postJson('/api/v1/backup-jobs', $this->manualPayload($destination, 'project_logs'))
            ->assertCreated();

        $this->assertModelExists($databaseTombstone);
        $this->assertModelExists($logsTombstone);
        $this->assertModelExists($historicalRun);
        $this->assertSame(2, BackupJob::query()->where('configuration_source', BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL)->count());
        $this->assertSame(2, BackupJob::query()->where('configuration_source', BackupJob::CONFIGURATION_SOURCE_MANUAL)->count());
    }

    public function test_managed_volume_reservation_requires_valid_label_reconciliation_state(): void
    {
        $destination = $this->destination();

        foreach ([BackupJob::STATUS_ACTIVE, BackupJob::STATUS_PAUSED, BackupJob::STATUS_ERROR] as $index => $status) {
            $job = $this->managedJob($destination, [
                'configuration_key' => hash('sha256', 'valid-reservation-'.$index),
                'status' => $status,
                'last_error' => $status === BackupJob::STATUS_ERROR ? 'Backup failed.' : null,
            ]);

            $this->assertTrue($job->reservesDockerVolume('project_database'));
        }

        $pending = $this->managedJob($destination, [
            'configuration_key' => hash('sha256', 'pending-reservation'),
            'volume_name' => 'current_volume',
            'pending_label_reconciliation' => [
                'action' => 'apply',
                'payload' => [
                    'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
                    'volume_name' => 'target_volume',
                ],
            ],
        ]);
        $this->assertTrue($pending->reservesDockerVolume('current_volume'));
        $this->assertTrue($pending->reservesDockerVolume('target_volume'));

        $pending->update(['label_reconciliation_error' => 'Definition disappeared.']);
        $this->assertFalse($pending->refresh()->reservesDockerVolume('current_volume'));
        $this->assertFalse($pending->reservesDockerVolume('target_volume'));
    }

    public function test_label_managed_jobs_cannot_be_edited_or_deleted(): void
    {
        $job = BackupJob::create([
            'name' => 'database',
            'volume_name' => 'project_database',
            'backup_destination_id' => $this->destination()->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
            'configuration_source' => BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL,
            'configuration_key' => hash('sha256', 'docker-label:project/db:database'),
        ]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $payload = [
            'name' => 'changed',
            'volume_name' => $job->volume_name,
            'backup_destination_id' => $job->backup_destination_id,
            'schedule_type' => $job->schedule_type,
            'schedule_config' => $job->schedule_config,
        ];

        $this->actingAs($admin)->get(route('backup-jobs.edit', $job))->assertForbidden();
        $this->actingAs($admin)->put(route('backup-jobs.update', $job), $payload)->assertForbidden();
        $this->actingAs($admin)->delete(route('backup-jobs.destroy', $job))->assertForbidden();

        $token = $admin->createToken('vv', ['read', 'write'])->plainTextToken;
        $this->withToken($token)
            ->putJson("/api/v1/backup-jobs/{$job->id}", $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('job');
        $this->withToken($token)
            ->deleteJson("/api/v1/backup-jobs/{$job->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('job');

        $this->assertModelExists($job);
    }

    public function test_enabling_settings_rejects_an_inactive_destination(): void
    {
        $destination = $this->destination();
        $destination->update(['is_active' => false]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('settings.docker-label-backups.update'), [
                'enabled' => true,
                'backup_destination_id' => $destination->id,
                'schedule_type' => BackupJob::SCHEDULE_DAILY,
                'schedule_config' => ['time' => '02:00'],
                'backup_filter_mode' => BackupJob::FILTER_MODE_EXCLUDE,
                'notifications_enabled' => true,
                'alert_notifications_enabled' => true,
                'stop_containers_before_backup' => false,
            ])
            ->assertSessionHasErrors('backup_destination_id');

        $this->assertFalse(DockerLabelBackupSetting::current()->enabled);
    }

    public function test_invalid_managed_job_cannot_be_resumed_on_web_or_api(): void
    {
        $job = $this->managedJob($this->destination(), [
            'status' => BackupJob::STATUS_ERROR,
            'label_reconciliation_error' => 'Definition missing.',
            'pending_label_reconciliation' => ['action' => 'disable', 'message' => 'Definition missing.'],
        ]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('backup-jobs.resume', $job))
            ->assertSessionHas('error');

        $token = $admin->createToken('vv', ['read', 'write'])->plainTextToken;
        $this->withToken($token)
            ->postJson("/api/v1/backup-jobs/{$job->id}/resume")
            ->assertStatus(422)
            ->assertJsonValidationErrors('job');

        $this->assertSame(BackupJob::STATUS_ERROR, $job->refresh()->status);
    }

    public function test_managed_job_with_an_ordinary_backup_error_can_be_resumed_on_web_and_api(): void
    {
        $job = $this->managedJob($this->destination(), [
            'status' => BackupJob::STATUS_ERROR,
            'last_error' => 'Backup failed.',
            'last_error_at' => now(),
        ]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('backup-jobs.resume', $job))->assertSessionHas('success');
        $this->assertSame(BackupJob::STATUS_ACTIVE, $job->refresh()->status);

        $job->update(['status' => BackupJob::STATUS_ERROR, 'last_error' => 'Backup failed.', 'last_error_at' => now()]);
        $token = $admin->createToken('vv', ['read', 'write'])->plainTextToken;
        $this->withToken($token)->postJson("/api/v1/backup-jobs/{$job->id}/resume")->assertOk();
        $this->assertSame(BackupJob::STATUS_ACTIVE, $job->refresh()->status);
    }

    public function test_reconciliation_retries_with_locked_defaults_when_settings_change_concurrently(): void
    {
        $destination = $this->destination();
        $settings = $this->settings($destination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $list = Mockery::mock(ListDockerLabelBackupContainers::class);
        $list->shouldReceive('handle')->twice()->andReturn([$this->labeledContainer()]);
        $locks = new class($settings) extends WithDockerLabelMutationLocks
        {
            private bool $changed = false;

            public function __construct(private readonly DockerLabelBackupSetting $settings) {}

            public function handle(array $destinationIds, callable $callback, array $volumeNames = [], array $notificationChannelIds = [], array $explicitJobIds = []): mixed
            {
                if (! $this->changed) {
                    $this->changed = true;
                    $this->settings->update([
                        'defaults' => [...$this->settings->resolvedDefaults(), 'retention_count' => 9],
                    ]);
                }

                return parent::handle($destinationIds, $callback, $volumeNames, $notificationChannelIds, $explicitJobIds);
            }
        };

        $result = (new ReconcileDockerLabelBackupJobs(
            $list,
            app(ParseDockerLabelBackupDefinitions::class),
            app(SelectAuthoritativeDockerLabelBackupContainers::class),
            app(BackupScheduleCalculator::class),
            app(ApplyPendingDockerLabelReconciliation::class),
            $locks,
        ))->handle();

        $this->assertSame(1, $result['created']);
        $this->assertSame(9, BackupJob::firstOrFail()->retention_count);
    }

    public function test_reconciliation_applies_pending_changes_without_reentering_the_lock_protocol(): void
    {
        $destination = $this->destination();
        $this->settings($destination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $job = $this->managedJob($destination, ['retention_count' => 9]);
        $list = Mockery::mock(ListDockerLabelBackupContainers::class);
        $list->shouldReceive('handle')->once()->andReturn([$this->labeledContainer()]);
        $pendingAction = new class(app(WithDockerLabelMutationLocks::class)) extends ApplyPendingDockerLabelReconciliation
        {
            public int $standaloneCalls = 0;

            public int $lockedCalls = 0;

            public function handle(BackupJob $job): bool
            {
                $this->standaloneCalls++;

                return parent::handle($job);
            }

            public function handleLocked(?BackupJob $job, Collection $destinations, Collection $volumes, Collection $notificationChannels): bool
            {
                $this->lockedCalls++;

                return parent::handleLocked($job, $destinations, $volumes, $notificationChannels);
            }
        };

        (new ReconcileDockerLabelBackupJobs(
            $list,
            app(ParseDockerLabelBackupDefinitions::class),
            app(SelectAuthoritativeDockerLabelBackupContainers::class),
            app(BackupScheduleCalculator::class),
            $pendingAction,
            app(WithDockerLabelMutationLocks::class),
        ))->handle();

        $this->assertSame(0, $pendingAction->standaloneCalls);
        $this->assertSame(1, $pendingAction->lockedCalls);
        $this->assertNull($job->refresh()->retention_count);
    }

    public function test_reconciliation_handles_a_channel_deleted_before_the_transaction(): void
    {
        $destination = $this->destination();
        $channel = $this->channel('Concurrent channel');
        $this->settings($destination);
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $list = Mockery::mock(ListDockerLabelBackupContainers::class);
        $list->shouldReceive('handle')->twice()->andReturn([$this->labeledContainer([
            'notification-channels' => $channel->name,
        ])]);
        $locks = new class($channel) extends WithDockerLabelMutationLocks
        {
            private bool $deleted = false;

            public function __construct(private readonly NotificationChannel $channel) {}

            public function handle(array $destinationIds, callable $callback, array $volumeNames = [], array $notificationChannelIds = [], array $explicitJobIds = []): mixed
            {
                if (! $this->deleted) {
                    $this->deleted = true;
                    app(DeleteNotificationChannel::class)->handle($this->channel);
                }

                return parent::handle($destinationIds, $callback, $volumeNames, $notificationChannelIds, $explicitJobIds);
            }
        };

        $result = (new ReconcileDockerLabelBackupJobs(
            $list,
            app(ParseDockerLabelBackupDefinitions::class),
            app(SelectAuthoritativeDockerLabelBackupContainers::class),
            app(BackupScheduleCalculator::class),
            app(ApplyPendingDockerLabelReconciliation::class),
            $locks,
        ))->handle();

        $this->assertSame(1, $result['conflicts']);
        $this->assertSame(1, $result['errors']);
        $this->assertSame(0, BackupJob::count());
    }

    public function test_run_creation_retries_when_the_authoritative_destination_changes_before_locking(): void
    {
        $destination = $this->destination();
        $otherDestination = $this->destination('Other');
        DockerVolume::create(['name' => 'project_database', 'exists' => true]);
        $job = $this->managedJob($destination);
        $locks = new class($job, $otherDestination) extends WithDockerLabelMutationLocks
        {
            public array $requests = [];

            public array $managedJobRequests = [];

            private bool $changed = false;

            public function __construct(
                private readonly BackupJob $job,
                private readonly BackupDestination $destination,
            ) {}

            public function handleForJobs(array $managedJobIds, array $destinationIds, callable $callback, array $volumeNames = [], array $notificationChannelIds = [], array $explicitJobIds = []): mixed
            {
                $this->requests[] = $destinationIds;
                $this->managedJobRequests[] = $managedJobIds;

                if (! $this->changed) {
                    $this->changed = true;
                    $this->job->update(['backup_destination_id' => $this->destination->id]);
                }

                return parent::handleForJobs($managedJobIds, $destinationIds, $callback, $volumeNames, $notificationChannelIds, $explicitJobIds);
            }
        };
        $action = new CreateBackupRun(
            app(BackupScheduleCalculator::class),
            $locks,
        );

        $run = $action->handle($job, BackupRun::TRIGGER_MANUAL);

        $this->assertSame($job->id, $run->backup_job_id);
        $this->assertSame([[$destination->id], [$otherDestination->id]], $locks->requests);
        $this->assertSame([[$job->id], [$job->id]], $locks->managedJobRequests);
    }

    private function reconcile(array $containers): array
    {
        $list = Mockery::mock(ListDockerLabelBackupContainers::class);
        $list->shouldReceive('handle')->zeroOrMoreTimes()->andReturn($containers);

        return (new ReconcileDockerLabelBackupJobs(
            $list,
            app(ParseDockerLabelBackupDefinitions::class),
            app(SelectAuthoritativeDockerLabelBackupContainers::class),
            app(BackupScheduleCalculator::class),
            app(ApplyPendingDockerLabelReconciliation::class),
            app(WithDockerLabelMutationLocks::class),
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

    private function destination(string $name = 'S3'): BackupDestination
    {
        return BackupDestination::create([
            'name' => $name,
            'provider' => BackupDestination::PROVIDER_AWS_S3,
            'bucket' => 'backups',
            'access_key_id' => 'access',
            'secret_access_key' => 'secret',
        ]);
    }

    private function managedJob(BackupDestination $destination, array $overrides = []): BackupJob
    {
        return BackupJob::create(array_merge([
            'name' => 'database',
            'volume_name' => 'project_database',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
            'next_run_at' => now()->addHour(),
            'configuration_source' => BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL,
            'configuration_key' => hash('sha256', 'docker-label:project/db:database'),
            'label_origin' => $this->composeOrigin('project', 'db', 'database', 'project-db-1'),
        ], $overrides));
    }

    private function composeOrigin(string $project, string $service, string $definitionName, string $container): array
    {
        return [
            'owner_type' => 'compose',
            'project' => $project,
            'service' => $service,
            'container' => $container,
            'definition_name' => $definitionName,
        ];
    }

    private function manualPayload(BackupDestination $destination, string $volumeName): array
    {
        return [
            'name' => 'Manual '.$volumeName,
            'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
            'volume_name' => $volumeName,
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
        ];
    }

    private function channel(string $name): NotificationChannel
    {
        return NotificationChannel::create([
            'name' => $name,
            'service' => NotificationChannel::SERVICE_ADVANCED,
            'url' => 'ntfy://ntfy.sh/'.str($name)->slug(),
            'notification_level' => NotificationChannel::LEVEL_INFO,
        ]);
    }

    private function labeledContainer(array $fields = []): array
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

        return [...$this->container(), 'running' => true, 'labels' => $labels];
    }

    private function container(): array
    {
        return [
            'id' => 'container-id',
            'name' => 'project-db-1',
            'labels' => [
                'com.docker.compose.project' => 'project',
                'com.docker.compose.service' => 'db',
            ],
            'mounts' => [[
                'name' => 'project_database',
                'destination' => '/var/lib/postgresql/data',
            ]],
        ];
    }
}
