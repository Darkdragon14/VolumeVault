<?php

namespace Tests\Feature;

use App\Actions\Backup\CreateBackupGroupRun;
use App\Actions\Backup\UpdateBackupJobGroup;
use App\Jobs\RunBackupGroupJob;
use App\Models\ActivityLog;
use App\Models\BackupDestination;
use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Models\DockerVolume;
use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class BackupJobGroupControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['db_data', 'cache_data', 'solo_data', 'member_vol'] as $volumeName) {
            DockerVolume::create(['name' => $volumeName, 'exists' => true]);
        }
    }

    public function test_creating_a_job_in_group_mode_with_a_new_group_creates_the_group_and_attaches_the_member(): void
    {
        $destination = $this->destination();

        $this->actingAs($this->admin())
            ->post(route('backup-jobs.store'), [
                'name' => 'DB volume',
                'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
                'volume_name' => 'db_data',
                'backup_destination_id' => $destination->id,
                'planning_mode' => 'group',
                'group_selection' => 'new',
                'new_group' => [
                    'name' => 'Nightly group',
                    'schedule_type' => BackupJobGroup::SCHEDULE_DAILY,
                    'schedule_config' => ['time' => '02:00'],
                    'failure_policy' => BackupJobGroup::FAILURE_POLICY_CONTINUE,
                    'notifications_enabled' => true,
                ],
            ])
            ->assertRedirect(route('backup-jobs.index'));

        $group = BackupJobGroup::firstWhere('name', 'Nightly group');
        $this->assertNotNull($group);
        $this->assertNotNull($group->next_run_at);

        $job = BackupJob::firstWhere('name', 'DB volume');
        $this->assertSame($group->id, $job->backup_job_group_id);
        // The group owns the schedule: the member never self-dispatches.
        $this->assertNull($job->next_run_at);
        $this->assertFalse($job->notifications_enabled);
    }

    public function test_creating_a_job_in_group_mode_attaches_it_to_an_existing_group(): void
    {
        $destination = $this->destination();
        $group = $this->group();

        $this->actingAs($this->admin())
            ->post(route('backup-jobs.store'), [
                'name' => 'Cache volume',
                'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
                'volume_name' => 'cache_data',
                'backup_destination_id' => $destination->id,
                'planning_mode' => 'group',
                'group_selection' => 'existing',
                'backup_job_group_id' => $group->id,
            ])
            ->assertRedirect(route('backup-jobs.index'));

        $job = BackupJob::firstWhere('name', 'Cache volume');
        $this->assertSame($group->id, $job->backup_job_group_id);
        $this->assertNull($job->next_run_at);
    }

    public function test_a_job_can_move_between_existing_groups(): void
    {
        $sourceGroup = $this->group();
        $targetGroup = $this->group();
        $targetGroup->forceFill(['name' => 'Target group'])->save();
        $job = $this->member($sourceGroup);

        $this->actingAs($this->admin())
            ->put(route('backup-jobs.update', $job), [
                'name' => $job->name,
                'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
                'volume_name' => $job->volume_name,
                'backup_destination_id' => $job->backup_destination_id,
                'planning_mode' => 'group',
                'group_selection' => 'existing',
                'backup_job_group_id' => $targetGroup->id,
            ])
            ->assertRedirect(route('backup-jobs.index'));

        $this->assertSame($targetGroup->id, $job->fresh()->backup_job_group_id);
        $this->assertFalse($sourceGroup->members()->exists());
    }

    public function test_a_job_can_detach_from_a_group_to_standalone_scheduling(): void
    {
        $group = $this->group();
        $job = $this->member($group);

        $this->actingAs($this->admin())
            ->put(route('backup-jobs.update', $job), [
                'name' => $job->name,
                'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
                'volume_name' => $job->volume_name,
                'backup_destination_id' => $job->backup_destination_id,
                'planning_mode' => 'standalone',
                'schedule_type' => BackupJob::SCHEDULE_DAILY,
                'schedule_config' => ['time' => '04:00'],
            ])
            ->assertRedirect(route('backup-jobs.index'));

        $job->refresh();
        $this->assertNull($job->backup_job_group_id);
        $this->assertNotNull($job->next_run_at);
    }

    public function test_a_standalone_job_still_keeps_its_own_schedule(): void
    {
        $destination = $this->destination();

        $this->actingAs($this->admin())
            ->post(route('backup-jobs.store'), [
                'name' => 'Standalone',
                'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
                'volume_name' => 'solo_data',
                'backup_destination_id' => $destination->id,
                'schedule_type' => BackupJob::SCHEDULE_DAILY,
                'schedule_config' => ['time' => '02:00'],
            ])
            ->assertRedirect(route('backup-jobs.index'));

        $job = BackupJob::firstWhere('name', 'Standalone');
        $this->assertNull($job->backup_job_group_id);
        $this->assertNotNull($job->next_run_at);
    }

    public function test_a_group_can_be_created_from_the_groups_section(): void
    {
        $this->actingAs($this->admin())
            ->post(route('backup-groups.store'), [
                'name' => 'Media',
                'schedule_type' => BackupJobGroup::SCHEDULE_WEEKLY,
                'schedule_config' => ['dayOfWeek' => 'sunday', 'time' => '03:00'],
                'failure_policy' => BackupJobGroup::FAILURE_POLICY_STOP,
                'notifications_enabled' => true,
            ])
            ->assertRedirect();

        $group = BackupJobGroup::firstWhere('name', 'Media');
        $this->assertNotNull($group);
        $this->assertSame(BackupJobGroup::FAILURE_POLICY_STOP, $group->failure_policy);
        $this->assertNotNull($group->next_run_at);
    }

    public function test_web_group_creation_revalidates_channels_and_rolls_back_the_group(): void
    {
        $channel = $this->notificationChannel('Deleted before web group channel lock');
        $this->deleteChannelAfterGroupCreation($channel);

        $this->actingAs($this->admin())
            ->post(route('backup-groups.store'), [
                'name' => 'Rolled back web group',
                'schedule_type' => BackupJobGroup::SCHEDULE_DAILY,
                'schedule_config' => ['time' => '02:00'],
                'failure_policy' => BackupJobGroup::FAILURE_POLICY_CONTINUE,
                'notification_channel_ids' => [$channel->id],
            ])
            ->assertSessionHasErrors('notification_channel_ids');

        $this->assertSame(0, BackupJobGroup::count());
        $this->assertSame(0, ActivityLog::where('event_type', 'backup_group_created')->count());
        $this->assertDatabaseHas('notification_channels', ['id' => $channel->id]);
    }

    public function test_web_group_creation_locks_channels_in_id_order_before_pivot_sync_and_activity(): void
    {
        $firstChannel = $this->notificationChannel('First ordered create channel');
        $secondChannel = $this->notificationChannel('Second ordered create channel');
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query;
        });

        $this->actingAs($this->admin())
            ->post(route('backup-groups.store'), [
                'name' => 'Ordered web group creation',
                'schedule_type' => BackupJobGroup::SCHEDULE_DAILY,
                'schedule_config' => ['time' => '02:00'],
                'failure_policy' => BackupJobGroup::FAILURE_POLICY_CONTINUE,
                'notification_channel_ids' => [$secondChannel->id, $firstChannel->id],
            ])
            ->assertRedirect();

        $groupInsert = collect($queries)->search(fn (QueryExecuted $query): bool => str_starts_with($query->sql, 'insert into "backup_job_groups"'));
        $channelLock = collect($queries)->search(fn (QueryExecuted $query): bool => str_contains($query->sql, 'select "id" from "notification_channels"')
            && str_contains($query->sql, 'order by "id" asc'));
        $pivotSync = collect($queries)->search(fn (QueryExecuted $query): bool => str_starts_with($query->sql, 'insert into "backup_job_group_notification_channel"'));
        $activityInsert = collect($queries)->search(fn (QueryExecuted $query): bool => str_starts_with($query->sql, 'insert into "activity_logs"'));

        $this->assertIsInt($groupInsert);
        $this->assertIsInt($channelLock);
        $this->assertIsInt($pivotSync);
        $this->assertIsInt($activityInsert);
        $this->assertStringContainsString(
            sprintf('in (%d, %d)', $firstChannel->id, $secondChannel->id),
            $queries[$channelLock]->sql,
        );
        $this->assertLessThan($channelLock, $groupInsert);
        $this->assertLessThan($pivotSync, $channelLock);
        $this->assertLessThan($activityInsert, $pivotSync);
    }

    public function test_deleting_a_group_is_blocked_while_it_has_members(): void
    {
        $group = $this->group();
        $this->member($group);

        // Flashes an error the groups index shows via the layout banner (it has no
        // form to bind field validation errors to), rather than a silent 422.
        $this->actingAs($this->admin())
            ->from(route('backup-groups.index'))
            ->delete(route('backup-groups.destroy', $group))
            ->assertRedirect(route('backup-groups.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('backup_job_groups', ['id' => $group->id]);
    }

    public function test_running_a_group_with_no_runnable_members_flashes_an_error(): void
    {
        $group = $this->group(); // active, no members

        // CreateBackupGroupRun rejects it; the index has no form to bind the
        // validation error to, so runNow must flash it to be visible.
        $this->actingAs($this->admin())
            ->from(route('backup-groups.index'))
            ->post(route('backup-groups.run', $group))
            ->assertRedirect(route('backup-groups.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, $group->groupRuns()->count());
    }

    public function test_deleting_a_group_is_blocked_while_a_run_is_in_flight(): void
    {
        $group = $this->group(); // no members
        BackupGroupRun::create([
            'backup_job_group_id' => $group->id,
            'status' => BackupGroupRun::STATUS_RUNNING,
            'trigger' => BackupGroupRun::TRIGGER_MANUAL,
            'started_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->from(route('backup-groups.index'))
            ->delete(route('backup-groups.destroy', $group))
            ->assertRedirect(route('backup-groups.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('backup_job_groups', ['id' => $group->id]);
    }

    public function test_an_empty_group_can_be_deleted(): void
    {
        $group = $this->group();

        $this->actingAs($this->admin())
            ->delete(route('backup-groups.destroy', $group))
            ->assertRedirect(route('backup-groups.index'));

        $this->assertDatabaseMissing('backup_job_groups', ['id' => $group->id]);
    }

    public function test_notifications_can_be_toggled_from_the_groups_section(): void
    {
        $group = $this->group();
        $this->assertTrue($group->notifications_enabled);

        $this->actingAs($this->admin())
            ->patch(route('backup-groups.notifications', $group), ['notifications_enabled' => false])
            ->assertRedirect();

        $this->assertFalse($group->fresh()->notifications_enabled);
    }

    public function test_running_a_group_now_queues_a_group_run(): void
    {
        config(['queue.default' => 'database']);
        Bus::fake([RunBackupGroupJob::class]);

        $group = $this->group();
        $this->member($group);

        $this->actingAs($this->admin())
            ->post(route('backup-groups.run', $group))
            ->assertRedirect();

        Bus::assertDispatched(RunBackupGroupJob::class);
        $this->assertSame(1, $group->groupRuns()->count());
    }

    public function test_group_run_uses_schedule_data_reloaded_under_the_group_lock(): void
    {
        $staleGroup = $this->group();
        $this->member($staleGroup);
        $staleGroup->newQuery()->whereKey($staleGroup->id)->update([
            'schedule_config' => ['time' => '07:00'],
            'cron_expression' => '0 7 * * *',
            'next_run_at' => now()->subMinute(),
        ]);

        $run = app(CreateBackupGroupRun::class)->handle($staleGroup, BackupGroupRun::TRIGGER_MANUAL);

        $this->assertNotNull($run);
        $this->assertSame(7, $staleGroup->fresh()->next_run_at->hour);
    }

    public function test_a_group_member_cannot_be_run_as_a_standalone_job(): void
    {
        $group = $this->group();
        $member = $this->member($group);

        $this->actingAs($this->admin())
            ->post(route('backup-jobs.run', $member))
            ->assertSessionHasErrors('job');

        $this->assertSame(0, $member->runs()->count());
    }

    public function test_updating_a_group_without_the_toggle_keeps_notifications_disabled(): void
    {
        $group = $this->group();
        $group->forceFill(['notifications_enabled' => false])->save();

        $this->actingAs($this->admin())
            ->put(route('backup-groups.update', $group), [
                'name' => 'Renamed',
                'schedule_type' => BackupJobGroup::SCHEDULE_DAILY,
                'schedule_config' => ['time' => '03:00'],
                'failure_policy' => BackupJobGroup::FAILURE_POLICY_CONTINUE,
                // notifications_enabled intentionally omitted
            ])
            ->assertRedirect(route('backup-groups.index'));

        $this->assertFalse($group->fresh()->notifications_enabled);
    }

    public function test_web_group_update_uses_locked_lifecycle_state_and_preserves_omitted_notifications(): void
    {
        $group = $this->group();
        $member = $this->member($group);
        $channel = $this->notificationChannel('Existing web channel');
        $group->forceFill(['notifications_enabled' => false])->save();
        $group->notificationChannels()->attach($channel);
        $lifecycleAt = now()->subHour()->startOfSecond();
        $changedAfterRouteBinding = false;

        Event::listen('eloquent.retrieved: '.BackupJobGroup::class, function (BackupJobGroup $retrieved) use ($group, $lifecycleAt, &$changedAfterRouteBinding): void {
            if ($changedAfterRouteBinding || $retrieved->id !== $group->id) {
                return;
            }

            $changedAfterRouteBinding = true;
            BackupJobGroup::query()->whereKey($group->id)->update([
                'status' => BackupJobGroup::STATUS_RUNNING,
                'pause_reason' => 'Lifecycle marker',
                'last_run_at' => $lifecycleAt,
                'last_success_at' => $lifecycleAt,
                'last_error' => 'Worker marker',
                'last_error_at' => $lifecycleAt,
            ]);
        });

        $this->actingAs($this->admin())
            ->put(route('backup-groups.update', $group), [
                'name' => 'Locked web update',
                'schedule_type' => BackupJobGroup::SCHEDULE_DAILY,
                'schedule_config' => ['time' => '05:00'],
                'failure_policy' => BackupJobGroup::FAILURE_POLICY_STOP,
            ])
            ->assertRedirect(route('backup-groups.index'));

        $freshGroup = $group->fresh();
        $this->assertSame(BackupJobGroup::STATUS_RUNNING, $freshGroup->status);
        $this->assertSame('Lifecycle marker', $freshGroup->pause_reason);
        $this->assertSame('Worker marker', $freshGroup->last_error);
        $this->assertTrue($freshGroup->last_run_at->equalTo($lifecycleAt));
        $this->assertTrue($freshGroup->last_success_at->equalTo($lifecycleAt));
        $this->assertTrue($freshGroup->last_error_at->equalTo($lifecycleAt));
        $this->assertFalse($freshGroup->notifications_enabled);
        $this->assertSame([$channel->id], $freshGroup->notificationChannels()->pluck('notification_channels.id')->all());
        $this->assertSame(['time' => '05:00'], $member->fresh()->schedule_config);
        $this->assertSame('0 5 * * *', $member->fresh()->cron_expression);
    }

    public function test_web_group_update_rolls_back_config_channels_and_member_propagation_together(): void
    {
        $group = $this->group();
        $member = $this->member($group);
        $existingChannel = $this->notificationChannel('Existing rollback channel');
        $replacementChannel = $this->notificationChannel('Replacement rollback channel');
        $group->forceFill(['notifications_enabled' => false])->save();
        $group->notificationChannels()->attach($existingChannel);
        $this->failAfterMemberScheduleUpdate();
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->admin())->put(route('backup-groups.update', $group), [
                'name' => 'Must roll back',
                'schedule_type' => BackupJobGroup::SCHEDULE_DAILY,
                'schedule_config' => ['time' => '06:00'],
                'failure_policy' => BackupJobGroup::FAILURE_POLICY_STOP,
                'notifications_enabled' => true,
                'notification_channel_ids' => [$replacementChannel->id],
            ]);
            $this->fail('The member propagation failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Stop after member propagation.', $exception->getMessage());
        } finally {
            Event::forget(QueryExecuted::class);
        }

        $freshGroup = $group->fresh();
        $this->assertSame('Group', $freshGroup->name);
        $this->assertSame(['time' => '02:00'], $freshGroup->schedule_config);
        $this->assertFalse($freshGroup->notifications_enabled);
        $this->assertSame([$existingChannel->id], $freshGroup->notificationChannels()->pluck('notification_channels.id')->all());
        $this->assertSame(['time' => '02:00'], $member->fresh()->schedule_config);
        $this->assertSame('0 2 * * *', $member->fresh()->cron_expression);
    }

    public function test_web_group_update_revalidates_channels_and_rolls_back_config(): void
    {
        $group = $this->group();
        $member = $this->member($group);
        $existingChannel = $this->notificationChannel('Existing revalidation channel');
        $missingChannel = $this->notificationChannel('Missing revalidation channel');
        $group->notificationChannels()->attach($existingChannel);
        Event::listen('eloquent.updated: '.BackupJobGroup::class, function (BackupJobGroup $updated) use ($group, $missingChannel): void {
            if ($updated->id === $group->id) {
                DB::table('notification_channels')->where('id', $missingChannel->id)->delete();
            }
        });

        $this->actingAs($this->admin())
            ->put(route('backup-groups.update', $group), [
                'name' => 'Must roll back after revalidation',
                'schedule_type' => BackupJobGroup::SCHEDULE_DAILY,
                'schedule_config' => ['time' => '06:00'],
                'failure_policy' => BackupJobGroup::FAILURE_POLICY_STOP,
                'notification_channel_ids' => [$missingChannel->id],
            ])
            ->assertSessionHasErrors('notification_channel_ids');

        $freshGroup = $group->fresh();
        $this->assertSame('Group', $freshGroup->name);
        $this->assertSame(['time' => '02:00'], $freshGroup->schedule_config);
        $this->assertSame([$existingChannel->id], $freshGroup->notificationChannels()->pluck('notification_channels.id')->all());
        $this->assertSame(['time' => '02:00'], $member->fresh()->schedule_config);
        $this->assertDatabaseHas('notification_channels', ['id' => $missingChannel->id]);
    }

    public function test_group_update_locks_members_in_id_order_before_channel_sync_and_bulk_update(): void
    {
        $group = $this->group();
        $this->member($group);
        $this->member($group);
        $channel = $this->notificationChannel('Ordered lock channel');
        $secondChannel = $this->notificationChannel('Second ordered lock channel');
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query;
        });

        app(UpdateBackupJobGroup::class)->handle($group, [
            'name' => 'Ordered locks',
            'schedule_type' => BackupJobGroup::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '06:00'],
            'timezone' => 'UTC',
            'failure_policy' => BackupJobGroup::FAILURE_POLICY_CONTINUE,
            'notification_channel_ids' => [$secondChannel->id, $channel->id],
        ]);

        $groupLockQuery = collect($queries)->search(fn (QueryExecuted $query): bool => str_contains($query->sql, 'select * from "backup_job_groups"')
            && str_contains($query->sql, 'order by "id" asc'));
        $memberLockQuery = collect($queries)->search(fn (QueryExecuted $query): bool => str_contains($query->sql, 'select * from "backup_jobs"')
            && str_contains($query->sql, 'order by "id" asc'));
        $channelLockQuery = collect($queries)->search(fn (QueryExecuted $query): bool => str_contains($query->sql, 'select "id" from "notification_channels"')
            && str_contains($query->sql, 'order by "id" asc'));
        $channelSyncQuery = collect($queries)->search(fn (QueryExecuted $query): bool => str_contains($query->sql, 'from "backup_job_group_notification_channel"'));
        $memberUpdateQuery = collect($queries)->search(fn (QueryExecuted $query): bool => str_starts_with($query->sql, 'update "backup_jobs"'));

        $this->assertIsInt($groupLockQuery);
        $this->assertIsInt($memberLockQuery);
        $this->assertIsInt($channelLockQuery);
        $this->assertIsInt($channelSyncQuery);
        $this->assertIsInt($memberUpdateQuery);
        $this->assertStringContainsString(
            sprintf('in (%d, %d)', $channel->id, $secondChannel->id),
            $queries[$channelLockQuery]->sql,
        );
        $this->assertLessThan($memberLockQuery, $groupLockQuery);
        $this->assertLessThan($channelLockQuery, $memberLockQuery);
        $this->assertLessThan($channelSyncQuery, $channelLockQuery);
        $this->assertLessThan($memberUpdateQuery, $memberLockQuery);
    }

    public function test_missing_schedule_input_is_a_validation_error_not_a_server_error(): void
    {
        // schedule_type/schedule_config omitted must surface as a 422 validation
        // error, never a TypeError 500 from the schedule normalizer.
        $this->actingAs($this->admin())
            ->post(route('backup-groups.store'), [
                'name' => 'No schedule',
                'failure_policy' => BackupJobGroup::FAILURE_POLICY_CONTINUE,
            ])
            ->assertSessionHasErrors('schedule_type');
    }

    public function test_resuming_a_grouped_member_keeps_next_run_at_null(): void
    {
        $group = $this->group();
        $member = $this->member($group);
        $member->forceFill(['status' => BackupJob::STATUS_PAUSED])->save();

        $this->actingAs($this->admin())
            ->post(route('backup-jobs.resume', $member))
            ->assertRedirect();

        $member->refresh();
        $this->assertSame(BackupJob::STATUS_ACTIVE, $member->status);
        $this->assertNull($member->next_run_at, 'a group member must not get a standalone next_run_at');
    }

    public function test_resuming_an_errored_member_resumes_its_errored_group(): void
    {
        $group = $this->group();
        $member = $this->member($group);
        // A group failure left both the group and the member in error.
        $group->forceFill(['status' => BackupJobGroup::STATUS_ERROR, 'last_error' => 'boom', 'last_error_at' => now()])->save();
        $member->forceFill(['status' => BackupJob::STATUS_ERROR, 'last_error' => 'boom'])->save();

        $this->actingAs($this->admin())
            ->post(route('backup-jobs.resume', $member))
            ->assertRedirect();

        // Resuming the member alone would leave the group in error and unscheduled;
        // it must bring the group back to active too.
        $this->assertSame(BackupJob::STATUS_ACTIVE, $member->fresh()->status);
        $this->assertSame(BackupJobGroup::STATUS_ACTIVE, $group->fresh()->status);
        $this->assertNull($group->fresh()->last_error);
    }

    public function test_resuming_an_errored_member_recomputes_the_group_next_run(): void
    {
        $group = $this->group();
        $member = $this->member($group);
        // The group failed and its next_run_at is overdue (left in the past).
        $group->forceFill(['status' => BackupJobGroup::STATUS_ERROR, 'next_run_at' => now()->subHour()])->save();
        $member->forceFill(['status' => BackupJob::STATUS_ERROR])->save();

        $this->actingAs($this->admin())
            ->post(route('backup-jobs.resume', $member))
            ->assertRedirect();

        $fresh = $group->fresh();
        $this->assertSame(BackupJobGroup::STATUS_ACTIVE, $fresh->status);
        // Recomputed to the future so the scheduler does not fire it immediately.
        $this->assertTrue($fresh->next_run_at->isFuture());
    }

    public function test_resuming_a_member_does_not_unpause_a_paused_group(): void
    {
        $group = $this->group();
        $member = $this->member($group);
        $group->forceFill(['status' => BackupJobGroup::STATUS_PAUSED, 'pause_reason' => 'maintenance'])->save();
        $member->forceFill(['status' => BackupJob::STATUS_PAUSED])->save();

        $this->actingAs($this->admin())
            ->post(route('backup-jobs.resume', $member))
            ->assertRedirect();

        // A paused group is a deliberate group-level action — resuming one member
        // must not un-pause the whole group.
        $this->assertSame(BackupJob::STATUS_ACTIVE, $member->fresh()->status);
        $this->assertSame(BackupJobGroup::STATUS_PAUSED, $group->fresh()->status);
    }

    public function test_a_running_group_cannot_be_resumed(): void
    {
        $group = $this->group();
        $group->forceFill(['status' => BackupJobGroup::STATUS_RUNNING])->save();

        // A stale Resume POST must not flip running -> active (which would then let a
        // pause slip in mid-run and be overwritten by the worker at the end).
        $this->actingAs($this->admin())
            ->from(route('backup-groups.index'))
            ->post(route('backup-groups.resume', $group))
            ->assertRedirect(route('backup-groups.index'))
            ->assertSessionHas('error');

        $this->assertSame(BackupJobGroup::STATUS_RUNNING, $group->fresh()->status);
    }

    public function test_pausing_a_running_member_job_flashes_a_visible_error(): void
    {
        $group = $this->group();
        $member = $this->member($group);
        $member->forceFill(['status' => BackupJob::STATUS_RUNNING])->save();

        // The jobs index renders only flash banners, so a stale Pause on a running
        // job must flash the reason rather than return an invisible validation error.
        $this->actingAs($this->admin())
            ->from(route('backup-jobs.index'))
            ->post(route('backup-jobs.pause', $member))
            ->assertRedirect(route('backup-jobs.index'))
            ->assertSessionHas('error');

        $this->assertSame(BackupJob::STATUS_RUNNING, $member->fresh()->status);
    }

    public function test_a_running_member_job_cannot_be_resumed(): void
    {
        $group = $this->group();
        $member = $this->member($group);
        $member->forceFill(['status' => BackupJob::STATUS_RUNNING])->save();

        $this->actingAs($this->admin())
            ->from(route('backup-jobs.index'))
            ->post(route('backup-jobs.resume', $member))
            ->assertRedirect(route('backup-jobs.index'))
            ->assertSessionHas('error');

        $this->assertSame(BackupJob::STATUS_RUNNING, $member->fresh()->status);
    }

    public function test_a_running_group_cannot_be_paused(): void
    {
        $group = $this->group();
        $group->forceFill(['status' => BackupJobGroup::STATUS_RUNNING])->save();

        // The conditional pause update matches 0 rows for a running group, so the
        // worker (which owns the running state) can never be un-paused from under
        // it. The index renders only flash banners, so the reason is flashed.
        $this->actingAs($this->admin())
            ->from(route('backup-groups.index'))
            ->post(route('backup-groups.pause', $group))
            ->assertRedirect(route('backup-groups.index'))
            ->assertSessionHas('error');

        $this->assertSame(BackupJobGroup::STATUS_RUNNING, $group->fresh()->status);
        $this->assertNull($group->fresh()->pause_reason);
    }

    public function test_group_edit_requires_admin(): void
    {
        $group = $this->group();

        $this->actingAs(User::factory()->create())
            ->get(route('backup-groups.edit', $group))
            ->assertForbidden();
    }

    public function test_group_edit_page_renders_the_form_for_admins(): void
    {
        $group = $this->group();
        $this->member($group);

        // The edit page reuses the BackupGroups/Form component and must hand it the
        // group with its members preloaded (so the form can list the attached jobs).
        $this->actingAs($this->admin())
            ->get(route('backup-groups.edit', $group))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('BackupGroups/Form')
                ->has('group.members', 1)
            );
    }

    public function test_group_show_page_renders_for_any_authenticated_user(): void
    {
        $group = $this->group();

        // The show route lives in the plain auth zone (not admin), so a read-only
        // user can open a group's detail page just like a job's.
        $this->actingAs(User::factory()->create())
            ->get(route('backup-groups.show', $group))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('BackupGroups/Show'));
    }

    public function test_group_show_lists_paginated_runs_with_their_aggregated_size(): void
    {
        $group = $this->group();
        $member = $this->member($group);

        // Three runs with distinct finished_at/created_at (forced, since SQLite
        // stores timestamps at 1-second resolution): an older success, a more
        // recent success, and an even more recent failure. "Last backup size" must
        // pick the most recent SUCCESS by finished_at (4096) — never the more
        // recent FAILED run, nor the older SUCCESS (9999).
        $this->groupRun($group, $member, BackupGroupRun::STATUS_SUCCESS, 9999, now()->subDays(2));
        $this->groupRun($group, $member, BackupGroupRun::STATUS_SUCCESS, 4096, now()->subHour());
        $this->groupRun($group, $member, BackupGroupRun::STATUS_FAILED, null, now());

        $this->actingAs($this->admin())
            ->get(route('backup-groups.show', $group))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('BackupGroups/Show')
                ->has('group.members', 1)
                // Runs are listed newest-first, each with its aggregated member size.
                ->has('runs.data', 3)
                ->where('runs.data.1.total_backup_size_bytes', 4096)
                ->where('runs.data.2.total_backup_size_bytes', 9999)
                ->where('lastSuccessfulGroupBackupSize', 4096)
            );
    }

    public function test_group_create_route_is_not_captured_by_show_route(): void
    {
        // create/edit are registered before the show wildcard, so /create must not
        // resolve to show with {backup_group} = "create".
        $this->actingAs($this->admin())
            ->get('/backup-groups/create')
            ->assertOk();
    }

    public function test_group_run_availability_tracks_execution_hosts_without_disabling_management(): void
    {
        $this->actingAs($this->admin());
        $group = $this->group();
        $member = $this->member($group);
        $assertAvailability = function (bool $available, string $reason = 'hostWorkflow.groupRunUnavailable') use ($group): void {
            foreach ([route('backup-groups.index') => 'groups.data.0', route('backup-groups.show', $group) => 'group'] as $url => $prefix) {
                $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
                    ->where($prefix.'.can_run', $available)
                    ->where($prefix.'.can_run_reason', $available ? null : $reason));
            }
        };

        config(['volumevault.mode' => 'hybrid']);
        $assertAvailability(true);
        config(['volumevault.mode' => 'orchestrator']);
        $assertAvailability(false, 'dockerHosts.localDisabled');
        $this->get(route('backup-groups.edit', $group))->assertOk();

        $host = DockerHost::factory()->create();
        $host->forceFill(['driver' => 'agent', 'agent_registered_at' => now(), 'agent_protocol_version' => 1,
            'agent_capabilities' => ['inventory-v1', 'backup-v1']])->save();
        $member->forceFill(['docker_host_id' => $host->id])->save();
        $assertAvailability(true);
        foreach (['agent_revoked_at' => now(), 'agent_protocol_version' => 99, 'agent_capabilities' => ['inventory-v1'], 'agent_registered_at' => null, 'maintenance_requested_at' => now()] as $attribute => $value) {
            $original = $host->getAttribute($attribute);
            $host->forceFill([$attribute => $value])->save();
            $assertAvailability(false, 'hostWorkflow.unavailable');
            $host->forceFill([$attribute => $original])->save();
        }

        $local = $this->member($group);
        $assertAvailability(false, 'dockerHosts.localDisabled');
        $local->update(['status' => 'paused']);
        $assertAvailability(true);
        DockerHost::findOrFail(1)->forceFill(['maintenance_requested_at' => now()])->save();
        $assertAvailability(false, 'hostWorkflow.unavailable');
        DockerHost::findOrFail(1)->forceFill(['maintenance_requested_at' => null])->save();
        $member->update(['status' => 'error']);
        $assertAvailability(true);
        $member->update(['status' => 'paused']);
        $assertAvailability(false);
        $member->update(['status' => 'active']);
        BackupGroupRun::create(['backup_job_group_id' => $group->id, 'status' => 'queued', 'trigger' => 'manual']);
        $assertAvailability(false);
    }

    public function test_group_list_batches_host_queries_and_does_not_expose_loaded_member_models(): void
    {
        $this->actingAs($this->admin());
        for ($i = 0; $i < 6; $i++) {
            $this->member($this->group());
        }
        $hostQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$hostQueries): void {
            if (str_contains($query->sql, 'from "docker_hosts"')) {
                $hostQueries[] = $query->sql;
            }
        });
        $this->get(route('backup-groups.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('groups.data', 6)
            ->where('groups.data.0.can_run', true)
            ->missing('groups.data.0.members'));
        $this->assertCount(1, $hostQueries);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function destination(): BackupDestination
    {
        return BackupDestination::create([
            'name' => 'Local',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'is_active' => true,
            'settings' => ['archive_path' => sys_get_temp_dir().'/vv'],
        ]);
    }

    private function group(): BackupJobGroup
    {
        return BackupJobGroup::create([
            'name' => 'Group',
            'schedule_type' => BackupJobGroup::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJobGroup::STATUS_ACTIVE,
            'failure_policy' => BackupJobGroup::FAILURE_POLICY_CONTINUE,
            'notifications_enabled' => true,
            'next_run_at' => now()->addDay(),
        ]);
    }

    private function member(BackupJobGroup $group): BackupJob
    {
        return BackupJob::create([
            'name' => 'Member',
            'backup_job_group_id' => $group->id,
            'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
            'volume_name' => 'member_vol',
            'backup_destination_id' => $this->destination()->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
            'next_run_at' => null,
        ]);
    }

    private function notificationChannel(string $name): NotificationChannel
    {
        return NotificationChannel::create([
            'name' => $name,
            'service' => NotificationChannel::SERVICE_ADVANCED,
            'url' => 'ntfy://ntfy.sh/backup-group-test',
            'notification_level' => NotificationChannel::LEVEL_INFO,
            'is_active' => true,
        ]);
    }

    private function failAfterMemberScheduleUpdate(): void
    {
        Event::listen(QueryExecuted::class, function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'update "backup_jobs"')) {
                throw new RuntimeException('Stop after member propagation.');
            }
        });
    }

    private function deleteChannelAfterGroupCreation(NotificationChannel $channel): void
    {
        Event::listen('eloquent.created: '.BackupJobGroup::class, function () use ($channel): void {
            DB::table('notification_channels')->where('id', $channel->id)->delete();
        });
    }

    private function groupRun(BackupJobGroup $group, BackupJob $member, string $status, ?int $size, Carbon $finishedAt): BackupGroupRun
    {
        $run = BackupGroupRun::create([
            'backup_job_group_id' => $group->id,
            'status' => $status,
            'trigger' => BackupGroupRun::TRIGGER_MANUAL,
            'total_members' => 1,
            'succeeded_members' => $status === BackupGroupRun::STATUS_SUCCESS ? 1 : 0,
        ]);

        // Pin finished_at and created_at together: the "last successful" query
        // orders by finished_at then created_at, and the run list orders by
        // created_at, so both must be deterministic despite SQLite's 1s resolution.
        $run->forceFill(['finished_at' => $finishedAt, 'created_at' => $finishedAt])->save();

        if ($size !== null) {
            BackupRun::create([
                'backup_job_id' => $member->id,
                'backup_group_run_id' => $run->id,
                'status' => BackupRun::STATUS_SUCCESS,
                'trigger' => BackupRun::TRIGGER_MANUAL,
                'backup_size_bytes' => $size,
            ]);
        }

        return $run;
    }
}
