<?php

namespace Tests\Feature;

use App\Actions\Backup\CreateBackupGroupRun;
use App\Actions\Backup\CreateBackupRun;
use App\Actions\Backup\RunBackup;
use App\Actions\Backup\RunBackupGroup;
use App\Actions\Docker\StartDockerContainers;
use App\Actions\Restore\CreateRestoreRun;
use App\Actions\Restore\RunRestore;
use App\Actions\Runs\DispatchQueuedRun;
use App\Jobs\RunBackupGroupJob;
use App\Jobs\RunBackupJob;
use App\Jobs\RunRestoreJob;
use App\Models\BackupDestination;
use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Models\RestoreRun;
use App\Services\Agents\AgentLifecycle;
use App\Services\Agents\HostWorkAdmission;
use App\Services\Docker\DockerProcess;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HostMaintenanceAdmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
        $this->mock(DockerProcess::class)->shouldNotReceive('run');
    }

    public function test_maintenance_holds_all_queued_run_types_without_publication_or_claim(): void
    {
        Queue::fake();
        $runs = $this->runs();
        $this->maintain(DockerHost::LOCAL_ID);

        foreach ($runs as $run) {
            $before = $run->refresh()->getAttributes();
            $this->assertFalse(app(DispatchQueuedRun::class)->handle($run));
            $action = match (true) {
                $run instanceof BackupRun => RunBackup::class,
                $run instanceof RestoreRun => RunRestore::class,
                default => RunBackupGroup::class,
            };
            app($action)->handle($run);
            $this->assertSame($before, $run->refresh()->getAttributes());
        }

        Queue::assertNothingPushed();
    }

    public function test_dequeued_work_is_released_for_sixty_seconds(): void
    {
        [$backup, $restore, $group] = $this->runs();
        $this->maintain(DockerHost::LOCAL_ID);

        foreach ([
            [new RunBackupJob($backup->id), RunBackup::class],
            [new RunRestoreJob($restore->id), RunRestore::class],
            [new RunBackupGroupJob($group->id), RunBackupGroup::class],
        ] as [$job, $action]) {
            $mock = $this->mock($action);
            $mock->shouldNotReceive('handle');
            $job->withFakeQueueInteractions()->handle($mock);
            $job->assertReleased(60);
        }
    }

    public function test_admission_uses_run_snapshot_and_restore_target_in_both_directions(): void
    {
        [$backup, $restore] = $this->runs();
        $remote = DockerHost::factory()->create();
        $backup->job->update(['docker_host_id' => $remote->id]);
        $restore->update(['source_docker_host_id' => DockerHost::LOCAL_ID, 'target_docker_host_id' => $remote->id]);
        $this->maintain(DockerHost::LOCAL_ID);
        $admission = app(HostWorkAdmission::class);

        $this->assertTrue($admission->forRun($backup));
        $this->assertFalse($admission->forRun($restore));

        DockerHost::whereKey(DockerHost::LOCAL_ID)->update(['maintenance_requested_at' => null]);
        $this->maintain($remote->id);
        $this->assertFalse($admission->forRun($backup));
        $this->assertTrue($admission->forRun($restore));
    }

    public function test_conditional_claim_rechecks_maintenance_after_a_stale_admission_read(): void
    {
        foreach ($this->runs() as $run) {
            $admission = app(HostWorkAdmission::class);
            DockerHost::whereKey(DockerHost::LOCAL_ID)->update(['maintenance_requested_at' => null]);
            $this->assertFalse($admission->forRun($run));
            $query = $admission->constrain($run->newQuery()->whereKey($run->id)->where('status', 'queued'));
            $this->maintain(DockerHost::LOCAL_ID);
            $this->assertSame(0, $query->update(['status' => 'running']));
            $this->assertSame('queued', $run->refresh()->status);
        }
    }

    public function test_new_backup_restore_and_group_creation_raise_validation_before_creating_runs(): void
    {
        [$backup, $restore, $group] = $this->runs();
        $this->maintain(DockerHost::LOCAL_ID);
        $backup->delete();
        $restore->delete();
        $groupModel = $group->group;
        $group->delete();

        foreach ([
            fn () => app(CreateBackupRun::class)->handle($backup->job, BackupRun::TRIGGER_MANUAL),
            fn () => app(CreateRestoreRun::class)->handle($restore->job, ['selected_backup_key' => 'backup.tar.gz']),
            fn () => app(CreateBackupGroupRun::class)->handle($groupModel, BackupGroupRun::TRIGGER_MANUAL),
        ] as $create) {
            try {
                $create();
                $this->fail('Maintenance must reject creation.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('docker_host_id', $exception->errors());
                $this->assertSame(422, $exception->status);
            }
        }

        $this->assertDatabaseCount('backup_runs', 0);
        $this->assertDatabaseCount('restore_runs', 0);
        $this->assertDatabaseCount('backup_group_runs', 0);
    }

    public function test_generated_group_waits_if_any_member_host_is_maintained(): void
    {
        [, , $group] = $this->runs();
        $remote = DockerHost::factory()->create();
        $member = $this->backupJob();
        $member->update(['backup_job_group_id' => $group->backup_job_group_id, 'docker_host_id' => $remote->id]);
        $this->maintain($remote->id);
        $this->assertTrue(app(HostWorkAdmission::class)->isWaiting($group));
        $this->assertFalse(app(DispatchQueuedRun::class)->handle($group));
    }

    public function test_queued_maintenance_survives_staleness_and_exhausted_publications_but_running_crashes_recover(): void
    {
        $runs = $this->runs();
        $this->maintain(DockerHost::LOCAL_ID);
        foreach ($runs as $run) {
            $run->forceFill(['created_at' => now()->subHours(3)])->save();
        }
        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        foreach ($runs as $run) {
            $this->assertSame('queued', $run->refresh()->status);
            $run->forceFill([
                'dispatch_token' => fake()->uuid(),
                'dispatch_published_at' => now()->subHours(3),
                'dispatch_attempted_at' => now()->subHours(2),
            ])->save();
        }
        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();
        foreach ($runs as $run) {
            $this->assertSame('queued', $run->refresh()->status);
        }

        $runs[0]->forceFill(['status' => 'running', 'started_at' => now()->subHours(3), 'last_heartbeat_at' => now()->subHours(3)])->save();
        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();
        $this->assertSame('failed', $runs[0]->refresh()->status);
    }

    public function test_active_runs_and_terminal_cleanup_are_not_held_by_maintenance(): void
    {
        [$backup, $restore, $group] = $this->runs();
        $this->maintain(DockerHost::LOCAL_ID);
        foreach ([$backup, $restore, $group] as $run) {
            $run->forceFill(['status' => 'running', 'last_heartbeat_at' => now()])->save();
            $this->assertFalse(app(HostWorkAdmission::class)->isWaiting($run));
        }
        $backup->forceFill(['status' => 'failed', 'stopped_container_ids' => ['application']])->save();
        $this->mock(StartDockerContainers::class)->shouldReceive('handle')->once()->with(['application']);
        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();
        $this->assertNull($backup->refresh()->stopped_container_ids);
        $this->assertSame('running', $restore->refresh()->status);
        $this->assertSame('running', $group->refresh()->status);
    }

    public function test_unknown_host_is_rejected_without_legacy_local_fallback(): void
    {
        $this->assertFalse(app(HostWorkAdmission::class)->isMaintained(999999));
        $this->expectException(ValidationException::class);
        app(HostWorkAdmission::class)->assertAccepting(999999);
    }

    public function test_waiting_work_can_be_published_after_maintenance_is_cleared(): void
    {
        Queue::fake();
        $runs = $this->runs();
        $this->maintain(DockerHost::LOCAL_ID);
        foreach ($runs as $run) {
            $this->assertFalse(app(DispatchQueuedRun::class)->handle($run));
        }
        DockerHost::whereKey(DockerHost::LOCAL_ID)->update(['maintenance_requested_at' => null]);
        foreach ($runs as $run) {
            $this->assertTrue(app(DispatchQueuedRun::class)->handle($run));
        }
        Queue::assertPushed(RunBackupJob::class, 1);
        Queue::assertPushed(RunRestoreJob::class, 1);
        Queue::assertPushed(RunBackupGroupJob::class, 1);
    }

    public static function unpublishedGroupStates(): array
    {
        return ['not attempted' => [false], 'expired first dispatch lease' => [true]];
    }

    #[DataProvider('unpublishedGroupStates')]
    public function test_unpublished_group_survives_reconciliation_before_dispatch_after_maintenance(bool $attempted): void
    {
        Queue::fake();
        [, , $run] = $this->runs();
        if ($attempted) {
            $run->forceFill(['dispatch_token' => 'unconfirmed-first-attempt', 'dispatch_attempted_at' => now()])->save();
        }
        $createdAt = $run->created_at;
        $host = DockerHost::findOrFail(DockerHost::LOCAL_ID);
        $lifecycle = app(AgentLifecycle::class);
        $lifecycle->setMaintenance($host, true);
        $this->assertFalse(app(DispatchQueuedRun::class)->handle($run));
        Queue::assertNothingPushed();

        $this->travel(2)->hours();
        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();
        $this->assertSame(BackupGroupRun::STATUS_QUEUED, $run->refresh()->status);
        $lifecycle->setMaintenance($host, false);

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();
        $this->travel(30)->minutes();
        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();
        $this->assertSame(BackupGroupRun::STATUS_QUEUED, $run->refresh()->status);
        $this->assertSame(BackupJobGroup::STATUS_ACTIVE, $run->group->status);
        $this->assertNull($run->error_message);
        $this->assertNull($run->finished_at);
        $this->assertNull($run->dispatch_published_at);
        $this->assertTrue($createdAt->equalTo($run->created_at));

        $this->artisan('volumevault:dispatch-queued-runs')->assertSuccessful();
        $run->refresh();
        Queue::assertPushed(RunBackupGroupJob::class, fn (RunBackupGroupJob $job): bool => $job->backupGroupRunId === $run->id && $job->dispatchToken === $run->dispatch_token);
        $this->assertNotNull($run->dispatch_published_at);
        $this->assertSame(BackupGroupRun::STATUS_QUEUED, $run->status);
        $this->assertSame(BackupJobGroup::STATUS_ACTIVE, $run->group->status);
    }

    public function test_resume_renews_exhausted_publication_budget_for_held_top_level_runs(): void
    {
        Queue::fake();
        $runs = $this->runs();
        foreach ($runs as $run) {
            $run->forceFill([
                'created_at' => now()->subHours(3),
                'dispatch_token' => 'previous-publication',
                'dispatch_published_at' => now()->subHours(2),
                'dispatch_attempted_at' => now()->subHour(),
            ])->save();
        }
        $host = DockerHost::findOrFail(DockerHost::LOCAL_ID);
        $lifecycle = app(AgentLifecycle::class);
        $lifecycle->setMaintenance($host, true);
        $this->travel(2)->hours();
        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();
        $lifecycle->setMaintenance($host, false);

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();
        foreach ($runs as $run) {
            $this->assertSame('queued', $run->refresh()->status);
            $this->assertNull($run->error_message);
        }
        $this->artisan('volumevault:dispatch-queued-runs')->assertSuccessful();
        Queue::assertPushed(RunBackupJob::class, 1);
        Queue::assertPushed(RunRestoreJob::class, 1);
        Queue::assertPushed(RunBackupGroupJob::class, 1);

        foreach ($runs as $run) {
            $run->refresh();
            $this->assertNotSame('previous-publication', $run->dispatch_token);
            $this->assertNotNull($run->dispatch_published_at);
            $publication = $run->only(['dispatch_token', 'dispatch_attempted_at', 'dispatch_published_at']);
            $lifecycle->setMaintenance($host, false);
            $this->assertEquals($publication, $run->refresh()->only(array_keys($publication)), 'Resuming twice must not reset fresh publications.');
        }
    }

    public function test_resume_resets_only_top_level_publications_assigned_to_that_host(): void
    {
        [$backup, $restore, $group] = $this->runs();
        $remote = DockerHost::factory()->create();
        $remoteBackup = $backup->replicate()->forceFill(['docker_host_id' => $remote->id]);
        $remoteBackup->save();
        $remoteRestore = $restore->replicate()->forceFill(['target_docker_host_id' => $remote->id]);
        $remoteRestore->save();
        $restore->update(['source_docker_host_id' => $remote->id]);
        [, , $remoteGroup] = $this->runs();
        $remoteGroup->group->members()->update(['docker_host_id' => $remote->id]);
        $member = $backup->replicate()->forceFill(['backup_group_run_id' => $group->id]);
        $member->save();
        $safetyBackup = $backup->replicate()->forceFill(['trigger' => BackupRun::TRIGGER_PRE_RESTORE]);
        $safetyBackup->save();
        $preserved = [$remoteBackup, $remoteRestore, $remoteGroup, $member, $safetyBackup];
        foreach ([$backup, $restore, $group, ...$preserved] as $run) {
            $run->forceFill([
                'dispatch_token' => 'existing-generation',
                'dispatch_published_at' => now()->subHours(2),
                'dispatch_attempted_at' => now()->subHour(),
            ])->save();
        }
        $before = array_map(fn ($run): array => $run->refresh()->getAttributes(), $preserved);
        $host = DockerHost::findOrFail(DockerHost::LOCAL_ID);
        $lifecycle = app(AgentLifecycle::class);
        $lifecycle->setMaintenance($host, true);
        $lifecycle->setMaintenance($host, false);

        foreach ([$backup, $restore, $group] as $run) {
            $this->assertNull($run->refresh()->dispatch_token);
            $this->assertNull($run->dispatch_published_at);
            $this->assertSame('queued', $run->status);
        }
        $this->assertSame($before, array_map(fn ($run): array => $run->refresh()->getAttributes(), $preserved));
    }

    public function test_active_group_continues_its_next_member_after_maintenance_is_requested(): void
    {
        [, , $groupRun] = $this->runs();
        $second = $this->backupJob();
        $second->update(['backup_job_group_id' => $groupRun->backup_job_group_id, 'volume_name' => 'second_data']);
        $this->mock(RunBackup::class)->shouldReceive('handle')->twice()
            ->andReturnUsing(function (BackupRun $run, bool $acceptedOperation): void {
                $this->assertTrue($acceptedOperation);
                $this->maintain(DockerHost::LOCAL_ID);
                $run->forceFill(['status' => 'success', 'finished_at' => now()])->save();
            });

        app(RunBackupGroup::class)->handle($groupRun);

        $this->assertSame('success', $groupRun->refresh()->status);
        $this->assertSame(2, $groupRun->memberRuns()->where('status', 'success')->count());
    }

    public function test_internal_accepted_backup_is_claimed_even_during_maintenance(): void
    {
        [$backup] = $this->runs();
        $backup->update(['trigger' => BackupRun::TRIGGER_PRE_RESTORE]);
        $backup->job->destination->update(['is_active' => false]);
        $this->maintain(DockerHost::LOCAL_ID);

        app(RunBackup::class)->handle($backup, acceptedOperation: true);

        $this->assertNotNull($backup->refresh()->started_at);
        $this->assertSame('failed', $backup->status);
        $this->assertSame('The backup destination is inactive.', $backup->error_message);
    }

    public function test_claim_observes_maintenance_committed_after_the_admission_precheck(): void
    {
        $runs = $this->runs();
        $admission = app(HostWorkAdmission::class);
        foreach ($runs as $run) {
            $this->assertFalse($admission->forRun($run));
        }
        DB::transaction(function (): void {
            DockerHost::query()->lockForUpdate()->findOrFail(DockerHost::LOCAL_ID);
            $this->maintain(DockerHost::LOCAL_ID);
        });

        foreach ($runs as $run) {
            $this->assertSame(0, $admission->claim($run, ['started_at' => now()]));
            $this->assertSame('queued', $run->refresh()->status);
            $this->assertNull($run->started_at);
        }
    }

    public function test_sql_predicate_still_blocks_maintenance_injected_after_host_read(): void
    {
        $runs = $this->runs();
        $armed = false;
        DB::listen(function (QueryExecuted $event) use (&$armed): void {
            if ($armed && str_starts_with($event->sql, 'select * from "docker_hosts"')) {
                $armed = false;
                $this->maintain(DockerHost::LOCAL_ID);
            }
        });

        foreach ($runs as $run) {
            DockerHost::whereKey(DockerHost::LOCAL_ID)->update(['maintenance_requested_at' => null]);
            $armed = true;
            $this->assertSame(0, app(HostWorkAdmission::class)->claim($run, ['started_at' => now()]));
            $this->assertFalse($armed, 'The claim must read the host inside its transaction.');
            $this->assertSame('queued', $run->refresh()->status);
        }
    }

    public function test_claim_reads_snapshot_hosts_in_ascending_order_inside_transaction_before_updating_run(): void
    {
        [$backup, $restore, $group] = $this->runs();
        $first = DockerHost::factory()->create();
        $second = DockerHost::factory()->create();
        $third = DockerHost::factory()->create();
        $backup->update(['docker_host_id' => $third->id, 'backup_group_run_id' => $group->id]);
        $restore->update(['target_docker_host_id' => $second->id, 'source_docker_host_id' => $third->id]);
        $group->group->members()->update(['docker_host_id' => $second->id]);
        $extra = $this->backupJob();
        $extra->update(['docker_host_id' => $first->id, 'backup_job_group_id' => $group->backup_job_group_id]);

        $events = [];
        $outerLevel = DB::transactionLevel();
        DB::listen(function (QueryExecuted $event) use (&$events, $outerLevel): void {
            if (str_starts_with($event->sql, 'select * from "docker_hosts"')) {
                $events[] = (int) $event->bindings[0];
                $this->assertGreaterThan($outerLevel, $event->connection->transactionLevel());
            } elseif (preg_match('/^update "(backup_runs|restore_runs|backup_group_runs)"/', $event->sql)) {
                $events[] = 'update';
                $this->assertGreaterThan($outerLevel, $event->connection->transactionLevel());
            }
        });

        foreach ([[$backup, [$third->id]], [$restore, [$second->id]], [$group, [$first->id, $second->id, $third->id]]] as [$run, $hosts]) {
            $events = [];
            $this->assertSame(1, app(HostWorkAdmission::class)->claim($run, ['started_at' => now()]));
            $this->assertSame([...$hosts, 'update'], $events);
            $this->assertSame($outerLevel, DB::transactionLevel());
            $this->assertSame('running', $run->refresh()->status);
        }
    }

    public function test_maintenance_counter_sees_a_claim_that_won_admission_and_cannot_reclaim_it(): void
    {
        foreach ($this->runs() as $run) {
            DockerHost::whereKey(DockerHost::LOCAL_ID)->update(['maintenance_requested_at' => null]);
            $this->assertSame(1, app(HostWorkAdmission::class)->claim($run, ['started_at' => now()]));

            DB::transaction(function () use ($run): void {
                DockerHost::query()->lockForUpdate()->findOrFail(DockerHost::LOCAL_ID);
                $this->maintain(DockerHost::LOCAL_ID);
                $this->assertSame(1, $run->newQuery()->whereKey($run->id)->where('status', 'running')->count());
            });

            $before = $run->refresh()->getAttributes();
            $this->assertSame(0, app(HostWorkAdmission::class)->claim($run, ['started_at' => now()->addHour()]));
            $this->assertSame($before, $run->refresh()->getAttributes());
        }
    }

    private function maintain(int $hostId): void
    {
        DockerHost::query()->whereKey($hostId)->update(['maintenance_requested_at' => now()]);
    }

    private function backupJob(): BackupJob
    {
        $destination = BackupDestination::create([
            'name' => 'Local', 'provider' => BackupDestination::PROVIDER_LOCAL, 'bucket' => 'local',
            'access_key_id' => '', 'secret_access_key' => '', 'settings' => ['archive_path' => '/tmp/vv'],
        ]);

        return BackupJob::create([
            'name' => 'App data', 'volume_name' => 'app_data', 'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY, 'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *', 'status' => BackupJob::STATUS_ACTIVE,
        ]);
    }

    /** @return array{BackupRun, RestoreRun, BackupGroupRun} */
    private function runs(): array
    {
        $job = $this->backupJob();
        $backup = BackupRun::create([
            'backup_job_id' => $job->id, 'docker_host_id' => DockerHost::LOCAL_ID,
            'source_type_snapshot' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME, 'source_volume_name' => 'app_data',
            'status' => 'queued', 'trigger' => BackupRun::TRIGGER_MANUAL,
        ]);
        $restore = RestoreRun::create([
            'backup_job_id' => $job->id, 'backup_destination_id' => $job->backup_destination_id,
            'source_docker_host_id' => DockerHost::LOCAL_ID, 'target_docker_host_id' => DockerHost::LOCAL_ID,
            'selected_backup_key' => 'backup.tar.gz', 'source_volume_name' => 'app_data',
            'target_volume_name' => 'restored_data', 'mode' => RestoreRun::MODE_NEW_VOLUME, 'status' => 'queued',
        ]);
        $group = BackupJobGroup::create([
            'name' => 'Nightly', 'schedule_type' => BackupJobGroup::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'], 'cron_expression' => '0 2 * * *', 'status' => 'active',
        ]);
        $member = $this->backupJob();
        $member->update(['backup_job_group_id' => $group->id]);

        return [$backup, $restore, BackupGroupRun::create([
            'backup_job_group_id' => $group->id, 'status' => 'queued', 'trigger' => BackupGroupRun::TRIGGER_MANUAL,
        ])];
    }
}
