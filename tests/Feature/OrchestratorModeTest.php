<?php

namespace Tests\Feature;

use App\Actions\Alerts\DestinationStorageLimitCheck;
use App\Actions\Alerts\EnsureAlertRules;
use App\Actions\Backup\CreateBackupGroupRun;
use App\Actions\Backup\CreateBackupRun;
use App\Actions\Backup\ReconcileDockerLabelBackupJobs;
use App\Actions\Backup\RunBackup;
use App\Actions\Backup\RunBackupGroup;
use App\Actions\Docker\SyncDockerVolumes;
use App\Actions\Restore\CreateRestoreRun;
use App\Actions\Restore\RunRestore;
use App\Actions\Runs\DispatchQueuedRun;
use App\Actions\Runs\ProcessRunFinalization;
use App\Enums\AlertType;
use App\Jobs\DispatchDueBackupGroupsJob;
use App\Jobs\DispatchDueBackupJobsJob;
use App\Jobs\RunBackupGroupJob;
use App\Jobs\RunBackupJob;
use App\Jobs\RunRestoreJob;
use App\Jobs\SyncDockerVolumesJob;
use App\Models\AlertRule;
use App\Models\BackupDestination;
use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\DockerVolume;
use App\Models\RestoreRun;
use App\Models\RunFinalization;
use App\Models\User;
use App\Services\BackupDestinations\DestinationStorage;
use App\Services\BackupSources\HostPathPolicy;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\LocalDockerExecution;
use App\Support\DeploymentMode;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class OrchestratorModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['volumevault.mode' => 'orchestrator']);
    }

    public function test_all_process_entrypoints_refuse_execution_before_file_io_even_with_docker_host(): void
    {
        $process = new DockerProcess;
        $path = storage_path('framework/orchestrator-output-must-not-exist');
        $environment = ['DOCKER_HOST' => 'tcp://127.0.0.1:2375'];

        foreach ([
            fn () => $process->run(['docker', 'info'], environment: $environment),
            fn () => $process->runWithInputFile(['docker', 'info'], $path, environment: $environment),
            fn () => $process->runWithOutputFile(['docker', 'info'], $path, environment: $environment),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Docker execution must be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('orchestrator-only', $exception->getMessage());
            }
        }

        $this->assertFileDoesNotExist($path);
    }

    public function test_manual_actions_refuse_before_creating_runs_or_touching_inventory(): void
    {
        $job = $this->job();
        $group = $this->group();
        $job->update(['backup_job_group_id' => $group->id]);
        $this->mock(DockerProcess::class)->shouldNotReceive('run');

        foreach ([
            fn () => app(CreateBackupRun::class)->handle($job, BackupRun::TRIGGER_MANUAL),
            fn () => app(CreateRestoreRun::class)->handle($job, []),
            fn () => app(CreateBackupGroupRun::class)->handle($group, BackupGroupRun::TRIGGER_MANUAL),
            fn () => app(SyncDockerVolumes::class)->handle(),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Local operation must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('deployment_mode', $exception->errors());
            }
        }

        $this->assertDatabaseCount('backup_runs', 0);
        $this->assertDatabaseCount('restore_runs', 0);
        $this->assertDatabaseCount('backup_group_runs', 0);
        $this->assertTrue(DockerVolume::where('name', $job->volume_name)->firstOrFail()->exists);
    }

    public function test_api_and_web_backdoors_return_validation_errors_without_writes(): void
    {
        Queue::fake();
        $job = $this->job();
        $group = $this->group();
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->withToken($user->createToken('operations', ['read', 'write'])->plainTextToken);

        foreach ([
            '/api/v1/volumes/sync',
            '/api/v1/backup-jobs',
            "/api/v1/backup-jobs/{$job->id}/run",
            "/api/v1/backup-jobs/{$job->id}/restore",
            "/api/v1/backup-jobs/{$job->id}/resume",
            '/api/v1/backup-groups',
            "/api/v1/backup-groups/{$group->id}/run",
            '/api/v1/stacks/backup',
            '/volumes/sync',
            '/backup-jobs',
        ] as $url) {
            $this->postJson($url)->assertUnprocessable();
        }

        foreach ([BackupDestination::PROVIDER_LOCAL, BackupDestination::PROVIDER_DOCKER_VOLUME] as $provider) {
            $this->postJson('/api/v1/destinations', ['provider' => $provider])->assertUnprocessable();
        }

        $this->postJson("/api/v1/destinations/{$job->backup_destination_id}/test")->assertUnprocessable();
        $this->getJson("/api/v1/backup-jobs/{$job->id}/backups")->assertUnprocessable();
        $this->getJson("/backup-jobs/{$job->id}/backups")->assertUnprocessable();
        $this->getJson("/backup-jobs/{$job->id}/restore")->assertUnprocessable();
        $this->assertDatabaseCount('backup_jobs', 1);
        $this->assertDatabaseCount('backup_runs', 0);
        $this->assertDatabaseCount('restore_runs', 0);
        $this->assertNull($job->destination->fresh()->last_test_status);
        Queue::assertNothingPushed();
    }

    public function test_automatic_jobs_commands_and_reconciliation_preserve_existing_states(): void
    {
        Queue::fake();
        $job = $this->job();
        $group = $this->group();
        $run = $this->backupRun($job);
        $running = $this->backupRun($job, ['status' => BackupRun::STATUS_RUNNING, 'stopped_container_ids' => ['application']]);
        $restore = RestoreRun::create([
            'backup_job_id' => $job->id, 'backup_destination_id' => $job->backup_destination_id,
            'selected_backup_key' => 'backup.tar.gz', 'source_volume_name' => $job->volume_name,
            'target_volume_name' => 'restored', 'mode' => RestoreRun::MODE_NEW_VOLUME, 'status' => RestoreRun::STATUS_QUEUED,
        ]);
        $groupRun = BackupGroupRun::create([
            'backup_job_group_id' => $group->id, 'status' => BackupGroupRun::STATUS_QUEUED,
            'trigger' => BackupGroupRun::TRIGGER_MANUAL,
        ]);
        foreach ([$run, $running, $restore, $groupRun] as $existingRun) {
            $existingRun->forceFill(['created_at' => now()->subDay(), 'updated_at' => now()->subDay()])->save();
        }
        $before = collect([$job, $group, $run, $running, $restore, $groupRun])->map(fn ($model) => $model->fresh()->getAttributes())->all();
        $backup = $this->mock(RunBackup::class);
        $backup->shouldNotReceive('handle');
        $restoreAction = $this->mock(RunRestore::class);
        $restoreAction->shouldNotReceive('handle');
        $groupAction = $this->mock(RunBackupGroup::class);
        $groupAction->shouldNotReceive('handle');
        $this->mock(DockerProcess::class)->shouldNotReceive('run');

        (new RunBackupJob($run->id))->handle($backup);
        (new RunRestoreJob($restore->id))->handle($restoreAction);
        (new RunBackupGroupJob($groupRun->id))->handle($groupAction);
        app()->call([new DispatchDueBackupJobsJob, 'handle']);
        app()->call([new DispatchDueBackupGroupsJob, 'handle']);
        app()->call([new SyncDockerVolumesJob, 'handle']);
        app(ReconcileDockerLabelBackupJobs::class)->handle();
        $this->assertFalse(app(DispatchQueuedRun::class)->handle($run));
        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();
        $this->artisan('volumevault:dispatch-queued-runs')->assertSuccessful();
        $this->artisan('volumevault:host-path-allowlist:audit')->assertSuccessful();

        $this->assertSame($before, collect([$job, $group, $run, $running, $restore, $groupRun])->map(fn ($model) => $model->fresh()->getAttributes())->all());
        $this->assertDatabaseCount('backup_runs', 2);
        $this->assertDatabaseCount('activity_logs', 0);
        Queue::assertNothingPushed();
    }

    public function test_schedules_disable_only_local_work_and_resume_in_hybrid_mode(): void
    {
        $events = collect(app(Schedule::class)->events());
        foreach ($events as $event) {
            $name = ($event->command ?? '').' '.($event->description ?? '');
            $local = str_contains($name, 'SyncDockerVolumes')
                || str_contains($name, 'reconcile-stale-runs')
                || str_contains($name, 'host-path-allowlist:audit');

            $this->assertSame(! $local, $event->filtersPass($this->app), $name);
        }

        config(['volumevault.mode' => 'hybrid']);
        $this->assertTrue(DeploymentMode::localExecutionEnabled());
        foreach ($events as $event) {
            $this->assertTrue($event->filtersPass($this->app));
        }
    }

    public function test_runtime_actions_refuse_before_claiming_or_stopping_containers(): void
    {
        $job = $this->job();
        $run = $this->backupRun($job);
        $restore = RestoreRun::create([
            'backup_job_id' => $job->id, 'backup_destination_id' => $job->backup_destination_id,
            'selected_backup_key' => 'backup.tar.gz', 'source_volume_name' => $job->volume_name,
            'target_volume_name' => 'restored', 'mode' => RestoreRun::MODE_NEW_VOLUME, 'status' => RestoreRun::STATUS_QUEUED,
        ]);
        $groupRun = BackupGroupRun::create([
            'backup_job_group_id' => $this->group()->id, 'status' => BackupGroupRun::STATUS_QUEUED,
            'trigger' => BackupGroupRun::TRIGGER_MANUAL,
        ]);
        $this->mock(DockerProcess::class)->shouldNotReceive('run');

        foreach ([RunBackup::class => $run, RunRestore::class => $restore, RunBackupGroup::class => $groupRun] as $action => $model) {
            $before = $model->fresh()->getAttributes();
            try {
                app($action)->handle($model);
                $this->fail('The runtime must reject local execution.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('orchestrator-only', $exception->getMessage());
            }
            $this->assertSame($before, $model->fresh()->getAttributes());
        }
    }

    public function test_operational_inventory_is_empty_but_history_remains_readable(): void
    {
        $job = $this->job();
        $run = $this->backupRun($job);
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->withToken($user->createToken('history', ['read'])->plainTextToken);
        $this->mock(DockerProcess::class)->shouldNotReceive('run');

        $this->get('/volumes')->assertOk()->assertInertia(fn (Assert $page) => $page->has('volumes', 0));
        $this->get('/stacks')->assertOk()->assertInertia(fn (Assert $page) => $page->has('stacks', 0));
        $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->where('stats.total_volumes', 0));
        $this->get('/backup-jobs/create')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('volumes', 0)->has('containers', 0)->has('destinations', 0));
        $this->get('/destinations/create')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('hosts', 0)->where('providers', fn ($providers): bool => $providers->contains('value', 'local')));
        $this->getJson('/api/v1/volumes')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.stats.total_volumes', 0);
        $this->getJson("/api/v1/backup-runs/{$run->id}")->assertOk();
        $this->get("/backup-jobs/{$job->id}")->assertOk();
        $this->assertDatabaseCount('docker_volumes', 1);
        config(['volumevault.host_path_allowlist' => ['/srv']]);
        $this->assertSame([], app(HostPathPolicy::class)->allowedPrefixes());
    }

    public function test_local_storage_is_blocked_but_network_destinations_can_be_created(): void
    {
        $destination = $this->job()->destination;
        try {
            app(DestinationStorage::class)->listBackupObjects($destination);
            $this->fail('Local storage must be disabled.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('orchestrator-only', $exception->getMessage());
        }

        $user = User::factory()->create(['role' => 'admin']);
        $this->withToken($user->createToken('destinations', ['read', 'write'])->plainTextToken)
            ->postJson('/api/v1/destinations', [
                'name' => 'Network', 'provider' => BackupDestination::PROVIDER_AWS_S3,
                'bucket' => 'backups', 'region' => 'eu-west-1', 'access_key_id' => 'test', 'secret_access_key' => 'test',
            ])->assertCreated();
        LocalDockerExecution::assertDestination(BackupDestination::where('name', 'Network')->firstOrFail());
    }

    public function test_local_metadata_retries_are_preserved_and_do_not_starve_network_finalizations(): void
    {
        Queue::fake();
        $job = $this->job();
        $local = $this->metadata($this->backupRun($job, ['status' => BackupRun::STATUS_SUCCESS]));
        $network = BackupDestination::create([
            'name' => 'Network', 'provider' => BackupDestination::PROVIDER_AWS_S3,
            'bucket' => 'backups', 'access_key_id' => 'test', 'secret_access_key' => 'test',
        ]);
        $remote = $this->metadata($this->backupRun($job, [
            'status' => BackupRun::STATUS_SUCCESS, 'backup_destination_id_snapshot' => $network->id,
        ]));
        $before = $local->fresh()->getAttributes();
        $processor = app(ProcessRunFinalization::class);
        $processor->handle($local->id);
        $this->assertSame(1, $processor->dispatchDue(1));
        $this->assertSame($before, $local->fresh()->getAttributes());
        $this->assertNotNull($remote->fresh()->enqueue_token);

        $this->mock(RunBackup::class)->shouldReceive('detectArchiveMetadata')->once()->with($remote->backup_run_id)
            ->andReturn(['backup_key' => 'backup.tar.gz', 'backup_size_bytes' => 123]);
        app(ProcessRunFinalization::class)->handle($remote->id);
        $this->assertSame(RunFinalization::STATUS_COMPLETED, $remote->fresh()->status);
        $this->assertSame(123, $remote->backupRun->fresh()->backup_size_bytes);
    }

    public function test_storage_alert_checks_skip_local_destinations_without_failure_logs(): void
    {
        $local = $this->job()->destination;
        $network = BackupDestination::create([
            'name' => 'Network', 'provider' => BackupDestination::PROVIDER_AWS_S3,
            'bucket' => 'backups', 'access_key_id' => 'test', 'secret_access_key' => 'test',
            'is_active' => true, 'settings' => ['storage_limit_warning_bytes' => 10],
        ]);
        app(EnsureAlertRules::class)->handle();
        $rule = AlertRule::where('type', AlertType::DestinationStorageLimit->value)->firstOrFail();
        $rule->update(['enabled' => true]);
        $this->mock(DestinationStorage::class)->shouldReceive('storageUsage')->once()
            ->withArgs(fn (BackupDestination $destination): bool => $destination->is($network))
            ->andReturn(['used_bytes' => 20, 'object_count' => 1]);

        $result = app(DestinationStorageLimitCheck::class)->handleWithErrors($rule);

        $this->assertSame([$local->getMorphClass().':'.$local->id], $result['erroredSubjectKeys']);
        $this->assertCount(1, $result['findings']);
        $this->assertTrue($result['findings'][0]['subject']->is($network));
        $this->assertDatabaseCount('activity_logs', 0);
    }

    private function job(): BackupJob
    {
        $destination = BackupDestination::create([
            'name' => 'Local', 'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local', 'access_key_id' => '', 'secret_access_key' => '',
            'is_active' => true, 'settings' => ['archive_path' => '/srv/backups'],
        ]);
        DockerVolume::create(['name' => 'app_data', 'exists' => true]);

        return BackupJob::create([
            'name' => 'Backup', 'volume_name' => 'app_data', 'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY, 'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *', 'status' => BackupJob::STATUS_ACTIVE, 'next_run_at' => now()->subDay(),
        ]);
    }

    private function group(): BackupJobGroup
    {
        return BackupJobGroup::create([
            'name' => 'Group', 'schedule_type' => BackupJobGroup::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'], 'cron_expression' => '0 2 * * *',
            'status' => BackupJobGroup::STATUS_ACTIVE, 'next_run_at' => now()->subDay(),
        ]);
    }

    private function backupRun(BackupJob $job, array $attributes = []): BackupRun
    {
        return BackupRun::create($attributes + [
            'backup_job_id' => $job->id, 'status' => BackupRun::STATUS_QUEUED,
            'trigger' => BackupRun::TRIGGER_MANUAL, 'created_at' => now()->subDay(),
        ]);
    }

    private function metadata(BackupRun $run): RunFinalization
    {
        return RunFinalization::create([
            'backup_run_id' => $run->id, 'type' => RunFinalization::TYPE_ARCHIVE_METADATA,
            'deduplication_key' => 'metadata:'.$run->id, 'available_at' => now(),
        ]);
    }
}
