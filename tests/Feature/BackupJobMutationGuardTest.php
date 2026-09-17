<?php

namespace Tests\Feature;

use App\Actions\Backup\WithDockerLabelMutationLocks;
use App\Models\ActivityLog;
use App\Models\BackupDestination;
use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\DockerLabelBackupSetting;
use App\Models\DockerVolume;
use App\Models\NotificationChannel;
use App\Models\RestoreRun;
use App\Models\RunFinalization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BackupJobMutationGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_manual_job_is_locked_in_job_stage_before_notification_channels(): void
    {
        $job = $this->job('vol_a');
        $channel = NotificationChannel::create([
            'name' => 'Manual updates',
            'service' => NotificationChannel::SERVICE_ADVANCED,
            'url' => 'ntfy://ntfy.sh/manual-updates',
            'notification_level' => NotificationChannel::LEVEL_INFO,
            'is_active' => true,
        ]);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        app(WithDockerLabelMutationLocks::class)->handle(
            [$job->backup_destination_id],
            function ($destinations, $settings, $managedJobs, $volumes, $channels, $explicitJobs) use ($job, $channel): void {
                $this->assertFalse($managedJobs->has($job->id));
                $this->assertTrue($explicitJobs->get($job->id)->is($job));
                $this->assertTrue($channels->get($channel->id)->is($channel));
            },
            notificationChannelIds: [$channel->id],
            explicitJobIds: [$job->id],
        );

        $jobQuery = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from "backup_jobs"'));
        $channelQuery = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from "notification_channels"'));
        $this->assertIsInt($jobQuery);
        $this->assertIsInt($channelQuery);
        $this->assertLessThan($channelQuery, $jobQuery);
    }

    public function test_changing_the_source_is_blocked_while_a_run_is_in_progress(): void
    {
        $job = $this->job('vol_a');
        $this->runningRun($job);

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('backup-jobs.edit', $job))
            ->put(route('backup-jobs.update', $job), $this->payload(['volume_name' => 'vol_b']))
            ->assertRedirect(route('backup-jobs.edit', $job))
            ->assertSessionHasErrors('source_type');

        // Source unchanged: a source swap under the run's held lock is refused.
        $this->assertSame('vol_a', $job->fresh()->volume_name);
    }

    public function test_non_source_edits_are_allowed_while_a_run_is_in_progress(): void
    {
        $job = $this->job('vol_a');
        $this->runningRun($job);

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('backup-jobs.update', $job), $this->payload(['name' => 'Renamed', 'volume_name' => 'vol_a']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $job->fresh()->name);
    }

    public function test_web_and_api_group_attach_is_blocked_during_an_active_backup(): void
    {
        foreach ([false, true] as $api) {
            $job = $this->job('attach_'.(int) $api);
            $group = $this->group('Attach '.(int) $api);
            $this->runningRun($job);

            $response = $this->updateJob($api, $job, $this->existingGroupPayload($job, $group));
            $this->assertMembershipError($response, $api);

            $this->assertNull($job->fresh()->backup_job_group_id);
        }
    }

    public function test_web_and_api_group_detach_is_blocked_during_an_active_restore(): void
    {
        foreach ([false, true] as $api) {
            $group = $this->group('Detach '.(int) $api);
            $job = $this->job('detach_'.(int) $api);
            $job->update(['backup_job_group_id' => $group->id]);
            $this->runningRestore($job);

            $response = $this->updateJob($api, $job, $this->standalonePayload($job));
            $this->assertMembershipError($response, $api);

            $this->assertSame($group->id, $job->fresh()->backup_job_group_id);
        }
    }

    public function test_web_and_api_group_reassignment_is_blocked_while_a_backup_holds_containers(): void
    {
        foreach ([false, true] as $api) {
            $originalGroup = $this->group('Original '.(int) $api);
            $targetGroup = $this->group('Target '.(int) $api);
            $job = $this->job('reassign_'.(int) $api);
            $job->update(['backup_job_group_id' => $originalGroup->id]);
            BackupRun::create([
                'backup_job_id' => $job->id,
                'status' => BackupRun::STATUS_SUCCESS,
                'trigger' => BackupRun::TRIGGER_SCHEDULED,
                'started_at' => now()->subMinute(),
                'finished_at' => now(),
                'stopped_container_ids' => ['app-1'],
            ]);

            $response = $this->updateJob($api, $job, $this->existingGroupPayload($job, $targetGroup));
            $this->assertMembershipError($response, $api);

            $this->assertSame($originalGroup->id, $job->fresh()->backup_job_group_id);
        }
    }

    public function test_web_and_api_inline_group_rejection_rolls_back_before_creation_while_a_restore_holds_containers(): void
    {
        foreach ([false, true] as $api) {
            $job = $this->job('inline_'.(int) $api);
            RestoreRun::create([
                'backup_job_id' => $job->id,
                'backup_destination_id' => $job->backup_destination_id,
                'selected_backup_key' => 'backup.tar.gz',
                'source_volume_name' => $job->volume_name,
                'target_volume_name' => $job->volume_name,
                'mode' => RestoreRun::MODE_INPLACE,
                'status' => RestoreRun::STATUS_SUCCESS,
                'finished_at' => now(),
                'stopped_container_ids' => ['app-1'],
            ]);
            $groupCount = BackupJobGroup::count();

            $payload = $this->inlineGroupPayload($job->destination);
            $payload['volume_name'] = $job->volume_name;
            DockerVolume::firstOrCreate(['name' => $job->volume_name], ['exists' => true]);
            $response = $this->updateJob($api, $job, $payload);
            $this->assertMembershipError($response, $api);

            $this->assertNull($job->fresh()->backup_job_group_id);
            $this->assertSame($groupCount, BackupJobGroup::count());
            $this->assertSame(0, ActivityLog::where('event_type', 'backup_group_created')->count());
        }
    }

    public function test_web_and_api_unchanged_group_membership_edits_are_allowed_during_an_active_run(): void
    {
        foreach ([false, true] as $api) {
            $group = $this->group('Unchanged '.(int) $api);
            $job = $this->job('unchanged_'.(int) $api);
            $job->update(['backup_job_group_id' => $group->id]);
            $this->runningRun($job);
            $payload = $this->existingGroupPayload($job, $group);
            $payload['name'] = 'Renamed '.(int) $api;

            $this->assertMutationSuccessful($this->updateJob($api, $job, $payload), $api);

            $job->refresh();
            $this->assertSame($group->id, $job->backup_job_group_id);
            $this->assertSame('Renamed '.(int) $api, $job->name);

            $standaloneJob = $this->job('standalone_'.(int) $api);
            $this->runningRestore($standaloneJob);
            $payload = $this->standalonePayload($standaloneJob);
            $payload['name'] = 'Standalone renamed '.(int) $api;

            $this->assertMutationSuccessful($this->updateJob($api, $standaloneJob, $payload), $api);

            $standaloneJob->refresh();
            $this->assertNull($standaloneJob->backup_job_group_id);
            $this->assertSame('Standalone renamed '.(int) $api, $standaloneJob->name);
        }
    }

    public function test_web_and_api_create_attach_is_blocked_by_an_active_target_group_run(): void
    {
        foreach ([BackupGroupRun::STATUS_QUEUED, BackupGroupRun::STATUS_RUNNING] as $status) {
            foreach ([false, true] as $api) {
                $group = $this->group("Create {$status} ".(int) $api);
                $this->groupRun($group, $status);
                $payload = $this->payload([
                    'planning_mode' => 'group',
                    'group_selection' => 'existing',
                    'backup_job_group_id' => $group->id,
                    'volume_name' => "create_{$status}_".(int) $api,
                ]);

                $response = $this->createJob($api, $payload);
                $this->assertMembershipError($response, $api);

                $this->assertFalse(BackupJob::query()->where('volume_name', $payload['volume_name'])->exists());
            }
        }
    }

    public function test_web_and_api_detach_is_blocked_by_an_active_current_group_run(): void
    {
        foreach ([false, true] as $api) {
            $group = $this->group('Active detach '.(int) $api);
            $job = $this->job('active_detach_'.(int) $api);
            $job->update(['backup_job_group_id' => $group->id]);
            $this->groupRun($group, BackupGroupRun::STATUS_RUNNING);

            $this->assertMembershipError($this->updateJob($api, $job, $this->standalonePayload($job)), $api);
            $this->assertSame($group->id, $job->fresh()->backup_job_group_id);
        }
    }

    public function test_web_and_api_reassignment_is_blocked_by_an_active_requested_group_run(): void
    {
        foreach ([false, true] as $api) {
            $currentGroup = $this->group('Inactive current '.(int) $api);
            $requestedGroup = $this->group('Active requested '.(int) $api);
            $job = $this->job('active_reassign_'.(int) $api);
            $job->update(['backup_job_group_id' => $currentGroup->id]);
            $this->groupRun($requestedGroup, BackupGroupRun::STATUS_QUEUED);

            $this->assertMembershipError($this->updateJob($api, $job, $this->existingGroupPayload($job, $requestedGroup)), $api);
            $this->assertSame($currentGroup->id, $job->fresh()->backup_job_group_id);
        }
    }

    public function test_web_and_api_inline_group_change_from_an_active_group_is_rejected_before_creation(): void
    {
        foreach ([false, true] as $api) {
            $group = $this->group('Active inline current '.(int) $api);
            $job = $this->job('active_inline_'.(int) $api);
            $job->update(['backup_job_group_id' => $group->id]);
            $this->groupRun($group, BackupGroupRun::STATUS_RUNNING);
            $groupCount = BackupJobGroup::count();
            $payload = $this->inlineGroupPayload($job->destination);
            $payload['volume_name'] = $job->volume_name;
            DockerVolume::firstOrCreate(['name' => $job->volume_name], ['exists' => true]);

            $this->assertMembershipError($this->updateJob($api, $job, $payload), $api);

            $this->assertSame($group->id, $job->fresh()->backup_job_group_id);
            $this->assertSame($groupCount, BackupJobGroup::count());
            $this->assertSame(0, ActivityLog::where('event_type', 'backup_group_created')->count());
        }
    }

    public function test_web_and_api_unchanged_membership_edits_are_allowed_during_an_active_group_run(): void
    {
        foreach ([false, true] as $api) {
            $group = $this->group('Active unchanged '.(int) $api);
            $job = $this->job('active_unchanged_'.(int) $api);
            $job->update(['backup_job_group_id' => $group->id]);
            $this->groupRun($group, BackupGroupRun::STATUS_RUNNING);
            $payload = $this->existingGroupPayload($job, $group);
            $payload['name'] = 'Allowed rename '.(int) $api;

            $this->assertMutationSuccessful($this->updateJob($api, $job, $payload), $api);
            $this->assertSame('Allowed rename '.(int) $api, $job->fresh()->name);
            $this->assertSame($group->id, $job->fresh()->backup_job_group_id);
        }
    }

    public function test_web_and_api_group_membership_changes_are_allowed_after_terminal_success(): void
    {
        foreach ([false, true] as $api) {
            $job = $this->job('success_'.(int) $api);
            $group = $this->group('Success '.(int) $api);
            BackupRun::create([
                'backup_job_id' => $job->id,
                'status' => BackupRun::STATUS_SUCCESS,
                'trigger' => BackupRun::TRIGGER_SCHEDULED,
                'started_at' => now()->subMinute(),
                'finished_at' => now(),
            ]);
            $this->groupRun($group, BackupGroupRun::STATUS_SUCCESS);

            $this->assertMutationSuccessful(
                $this->updateJob($api, $job, $this->existingGroupPayload($job, $group)),
                $api,
            );

            $this->assertSame($group->id, $job->fresh()->backup_job_group_id);
        }
    }

    public function test_deleting_a_job_is_blocked_while_a_run_is_in_progress(): void
    {
        $job = $this->job('vol_a');
        $this->runningRun($job);

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('backup-jobs.index'))
            ->delete(route('backup-jobs.destroy', $job))
            ->assertRedirect(route('backup-jobs.index'))
            ->assertSessionHas('error');

        // The run row survives, so reconciliation can still restart its containers.
        $this->assertDatabaseHas('backup_jobs', ['id' => $job->id]);
    }

    public function test_api_deleting_a_job_with_a_run_in_progress_is_rejected(): void
    {
        $job = $this->job('vol_a');
        $this->runningRun($job);
        $token = User::factory()->admin()->create()->createToken('vv', ['read', 'write'])->plainTextToken;

        $this->withToken($token)
            ->deleteJson("/api/v1/backup-jobs/{$job->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('backup_jobs', ['id' => $job->id]);
    }

    public function test_deleting_a_job_is_blocked_while_a_terminal_run_still_holds_stopped_containers(): void
    {
        $job = $this->job('vol_a');
        // SUCCESS, but its finally has not yet restarted the containers it stopped.
        BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_SUCCESS,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'stopped_container_ids' => ['app-1'],
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('backup-jobs.index'))
            ->delete(route('backup-jobs.destroy', $job))
            ->assertRedirect(route('backup-jobs.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('backup_jobs', ['id' => $job->id]);
    }

    public function test_deleting_a_job_is_blocked_while_terminal_container_cleanup_is_pending(): void
    {
        $job = $this->job('vol_a');
        BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_FAILED,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'docker_container_id' => 'volumevault-backup-1-secret',
            'docker_container_cleanup_pending' => true,
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('backup-jobs.index'))
            ->delete(route('backup-jobs.destroy', $job))
            ->assertRedirect(route('backup-jobs.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('backup_jobs', ['id' => $job->id]);
    }

    public function test_deleting_a_job_is_blocked_while_a_restore_run_holds_stopped_containers(): void
    {
        $job = $this->job('vol_a');
        RestoreRun::create([
            'backup_job_id' => $job->id,
            'backup_destination_id' => $job->backup_destination_id,
            'selected_backup_key' => 'backup.tar.gz',
            'source_volume_name' => 'vol_a',
            'target_volume_name' => 'vol_a',
            'mode' => RestoreRun::MODE_INPLACE,
            'status' => RestoreRun::STATUS_SUCCESS,
            'finished_at' => now(),
            'stopped_container_ids' => ['app-1'],
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('backup-jobs.index'))
            ->delete(route('backup-jobs.destroy', $job))
            ->assertRedirect(route('backup-jobs.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('backup_jobs', ['id' => $job->id]);
    }

    public function test_deleting_a_destination_is_blocked_while_a_run_using_it_is_in_progress(): void
    {
        $job = $this->job('vol_a');
        $this->runningRun($job);

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('destinations.index'))
            ->delete(route('destinations.destroy', $job->backup_destination_id))
            ->assertRedirect(route('destinations.index'))
            ->assertSessionHas('error');

        // Deleting the destination would cascade the job and its in-flight run.
        $this->assertDatabaseHas('backup_destinations', ['id' => $job->backup_destination_id]);
        $this->assertDatabaseHas('backup_jobs', ['id' => $job->id]);
    }

    public function test_deleting_a_destination_is_blocked_while_terminal_container_cleanup_is_pending(): void
    {
        $job = $this->job('vol_a');
        $this->pendingContainerCleanupRun($job);

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('destinations.index'))
            ->delete(route('destinations.destroy', $job->backup_destination_id))
            ->assertRedirect(route('destinations.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('backup_destinations', ['id' => $job->backup_destination_id]);
        $this->assertDatabaseHas('backup_jobs', ['id' => $job->id]);
    }

    public function test_deleting_a_destination_is_blocked_by_a_restore_reading_from_it_after_the_job_moved(): void
    {
        $destA = $this->destination();
        $destB = $this->destination();
        // The job now points at B, but an in-flight restore still reads from A.
        $job = BackupJob::create([
            'name' => 'Job',
            'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
            'volume_name' => 'vol_a',
            'backup_destination_id' => $destB->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);
        RestoreRun::create([
            'backup_job_id' => $job->id,
            'backup_destination_id' => $destA->id,
            'selected_backup_key' => 'backup.tar.gz',
            'source_volume_name' => 'vol_a',
            'target_volume_name' => 'vol_a',
            'mode' => RestoreRun::MODE_INPLACE,
            'status' => RestoreRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        // No job points at A anymore, but the restore reads from it: block the delete
        // (deleting A nulls the restore's destination and its download would fail).
        $this->actingAs(User::factory()->admin()->create())
            ->from(route('destinations.index'))
            ->delete(route('destinations.destroy', $destA->id))
            ->assertRedirect(route('destinations.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('backup_destinations', ['id' => $destA->id]);
    }

    public function test_destination_configuration_use_is_narrower_than_deletion_use_for_historical_restores(): void
    {
        $source = $this->destination('Source');
        $current = $this->destination('Current');
        $restore = $this->restoreReading($source, $current, RestoreRun::MODE_NEW_VOLUME);

        $this->assertTrue($source->hasConfigurationInUse());
        $this->assertFalse($current->hasConfigurationInUse());
        $this->assertTrue($current->hasRunInProgress(includeAllFinalizations: true));

        $restore->update([
            'mode' => RestoreRun::MODE_INPLACE,
            'backup_before_overwrite' => true,
            'pre_restore_backup_run_id' => null,
        ]);

        $this->assertTrue($current->hasConfigurationInUse());

        $safetyBackup = BackupRun::create([
            'backup_job_id' => $restore->backup_job_id,
            'status' => BackupRun::STATUS_SUCCESS,
            'trigger' => BackupRun::TRIGGER_PRE_RESTORE,
            'finished_at' => now(),
        ]);
        $restore->update(['pre_restore_backup_run_id' => $safetyBackup->id]);

        $this->assertFalse($current->hasConfigurationInUse());
    }

    public function test_web_and_api_allow_updating_the_current_destination_during_restores_that_do_not_need_a_safety_backup(): void
    {
        foreach ([RestoreRun::MODE_NEW_VOLUME, RestoreRun::MODE_INPLACE] as $mode) {
            foreach ([false, true] as $api) {
                $source = $this->destination("Source {$mode} ".(int) $api);
                $current = $this->destination("Current {$mode} ".(int) $api);
                $this->restoreReading($source, $current, $mode);

                $response = $this->updateDestination($api, $current, "Updated {$mode} ".(int) $api);

                if ($api) {
                    $response->assertOk();
                } else {
                    $response->assertSessionHasNoErrors();
                }

                $this->assertSame("Updated {$mode} ".(int) $api, $current->fresh()->name);
            }
        }
    }

    public function test_web_and_api_block_destination_configuration_still_needed_by_a_restore(): void
    {
        foreach ([false, true] as $api) {
            $source = $this->destination('Blocked source '.(int) $api);
            $current = $this->destination('Blocked current '.(int) $api);
            $this->restoreReading($source, $current, RestoreRun::MODE_INPLACE, true);

            $this->assertDestinationUpdateBlocked($this->updateDestination($api, $source, 'Changed source '.(int) $api), $api);
            $this->assertDestinationUpdateBlocked($this->updateDestination($api, $current, 'Changed current '.(int) $api), $api);
            $this->assertSame('Blocked source '.(int) $api, $source->fresh()->name);
            $this->assertSame('Blocked current '.(int) $api, $current->fresh()->name);
        }
    }

    public function test_deleting_a_destination_is_blocked_while_a_group_run_uses_a_member_on_it(): void
    {
        $dest = $this->destination();
        $group = BackupJobGroup::create([
            'name' => 'Group',
            'schedule_type' => BackupJobGroup::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJobGroup::STATUS_RUNNING,
            'failure_policy' => BackupJobGroup::FAILURE_POLICY_CONTINUE,
            'notifications_enabled' => true,
        ]);
        $member = BackupJob::create([
            'name' => 'Member',
            'backup_job_group_id' => $group->id,
            'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
            'volume_name' => 'vol_a',
            'backup_destination_id' => $dest->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
            'next_run_at' => null,
        ]);
        // A group run is already running (no member BackupRun created yet).
        BackupGroupRun::create([
            'backup_job_group_id' => $group->id,
            'status' => BackupGroupRun::STATUS_RUNNING,
            'trigger' => BackupGroupRun::TRIGGER_SCHEDULED,
            'started_at' => now(),
        ]);

        // Deleting the destination would cascade-delete the member job mid-run.
        $this->actingAs(User::factory()->admin()->create())
            ->from(route('destinations.index'))
            ->delete(route('destinations.destroy', $dest->id))
            ->assertRedirect(route('destinations.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('backup_destinations', ['id' => $dest->id]);
        $this->assertDatabaseHas('backup_jobs', ['id' => $member->id]);
    }

    public function test_api_deleting_a_destination_with_a_run_in_progress_is_rejected(): void
    {
        $job = $this->job('vol_a');
        $this->runningRun($job);
        $token = User::factory()->admin()->create()->createToken('vv', ['read', 'write'])->plainTextToken;

        $this->withToken($token)
            ->deleteJson("/api/v1/destinations/{$job->backup_destination_id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('backup_destinations', ['id' => $job->backup_destination_id]);
    }

    public function test_destination_deletion_is_blocked_by_a_terminal_restore_with_an_outstanding_notification_on_web_and_api(): void
    {
        $job = $this->job('vol_a');
        $restore = RestoreRun::create([
            'backup_job_id' => $job->id,
            'backup_destination_id' => null,
            'selected_backup_key' => 'backup.tar.gz',
            'source_volume_name' => 'vol_a',
            'target_volume_name' => 'vol_a-restored',
            'mode' => RestoreRun::MODE_NEW_VOLUME,
            'status' => RestoreRun::STATUS_SUCCESS,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
        $channel = NotificationChannel::create([
            'name' => 'Restore notifications',
            'service' => NotificationChannel::SERVICE_ADVANCED,
            'url' => 'ntfy://ntfy.sh/restore-notifications',
            'notification_level' => NotificationChannel::LEVEL_INFO,
            'is_active' => true,
        ]);
        $finalization = $restore->finalizations()->create([
            'notification_channel_id' => $channel->id,
            'type' => RunFinalization::TYPE_FINISHED_NOTIFICATION,
            'deduplication_key' => "restore-run:{$restore->id}:finished-notification:channel:{$channel->id}",
            'status' => RunFinalization::STATUS_PENDING,
            'available_at' => now(),
        ]);
        $destination = $job->destination;
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->from(route('destinations.index'))
            ->delete(route('destinations.destroy', $destination))
            ->assertRedirect(route('destinations.index'))
            ->assertSessionHas('error');

        $token = $admin->createToken('vv', ['read', 'write'])->plainTextToken;
        $this->withToken($token)
            ->deleteJson("/api/v1/destinations/{$destination->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('destination');

        $this->assertModelExists($destination);
        $this->assertModelExists($job);
        $this->assertModelExists($restore);
        $this->assertModelExists($finalization);
    }

    public function test_api_deletions_are_blocked_while_terminal_container_cleanup_is_pending(): void
    {
        $job = $this->job('vol_a');
        $this->pendingContainerCleanupRun($job);
        $token = User::factory()->admin()->create()->createToken('vv', ['read', 'write'])->plainTextToken;

        $this->withToken($token)
            ->deleteJson("/api/v1/backup-jobs/{$job->id}")
            ->assertStatus(422);
        $this->withToken($token)
            ->deleteJson("/api/v1/destinations/{$job->backup_destination_id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('backup_destinations', ['id' => $job->backup_destination_id]);
        $this->assertDatabaseHas('backup_jobs', ['id' => $job->id]);
    }

    public function test_deleting_a_destination_used_by_a_label_managed_job_is_rejected_on_web_and_api(): void
    {
        $job = $this->job('vol_a');
        $job->update([
            'configuration_source' => BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL,
            'configuration_key' => hash('sha256', 'managed-job'),
        ]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->from(route('destinations.index'))
            ->delete(route('destinations.destroy', $job->backup_destination_id))
            ->assertRedirect(route('destinations.index'))
            ->assertSessionHas('error');

        $token = $admin->createToken('vv', ['read', 'write'])->plainTextToken;
        $this->withToken($token)
            ->deleteJson("/api/v1/destinations/{$job->backup_destination_id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('destination');

        $this->assertModelExists($job);
        $this->assertDatabaseHas('backup_destinations', ['id' => $job->backup_destination_id]);
    }

    public function test_enabled_default_destination_cannot_be_deleted_or_deactivated_without_jobs(): void
    {
        $destination = $this->destination();
        DockerLabelBackupSetting::current()->update([
            'enabled' => true,
            'backup_destination_id' => $destination->id,
        ]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->from(route('destinations.index'))
            ->delete(route('destinations.destroy', $destination))
            ->assertSessionHas('error');
        $this->actingAs($admin)
            ->patch(route('destinations.active', $destination), ['is_active' => false])
            ->assertSessionHas('error');

        $token = $admin->createToken('vv', ['read', 'write'])->plainTextToken;
        $this->withToken($token)
            ->deleteJson("/api/v1/destinations/{$destination->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('destination');
        $this->withToken($token)
            ->putJson("/api/v1/destinations/{$destination->id}", [
                'name' => $destination->name,
                'provider' => $destination->provider,
                'bucket' => $destination->bucket,
                'is_active' => false,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_active');

        $this->assertTrue($destination->fresh()->is_active);
        $this->assertModelExists($destination);
    }

    public function test_destination_activation_flash_uses_the_requested_state(): void
    {
        $destination = $this->destination();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->from(route('destinations.index'))
            ->patch(route('destinations.active', $destination), ['is_active' => false])
            ->assertSessionHas('success', 'Destination disabled.');

        $this->actingAs($admin)
            ->from(route('destinations.index'))
            ->patch(route('destinations.active', $destination), ['is_active' => true])
            ->assertSessionHas('success', 'Destination enabled.');
    }

    public function test_web_manual_job_creation_rechecks_label_managed_volume_after_locking(): void
    {
        $destination = $this->destination();
        DockerVolume::create(['name' => 'vol_a', 'exists' => true]);
        $this->installManagedJobBeforeLock($destination);
        $admin = User::factory()->admin()->create();
        $payload = $this->payload(['backup_destination_id' => $destination->id]);

        $this->actingAs($admin)
            ->post(route('backup-jobs.store'), $payload)
            ->assertSessionHasErrors('volume_name');

        $this->assertSame(1, BackupJob::where('volume_name', 'vol_a')->count());
        $this->assertTrue(BackupJob::firstOrFail()->isDockerLabelManaged());
    }

    public function test_api_manual_job_creation_rechecks_label_managed_volume_after_locking(): void
    {
        $destination = $this->destination();
        DockerVolume::create(['name' => 'vol_a', 'exists' => true]);
        $this->installManagedJobBeforeLock($destination);
        $token = User::factory()->admin()->create()->createToken('vv', ['read', 'write'])->plainTextToken;
        $payload = $this->payload(['backup_destination_id' => $destination->id]);

        $this->withToken($token)
            ->postJson('/api/v1/backup-jobs', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('volume_name');

        $this->assertSame(1, BackupJob::where('volume_name', 'vol_a')->count());
        $this->assertTrue(BackupJob::firstOrFail()->isDockerLabelManaged());
    }

    public function test_web_manual_job_creation_reports_a_destination_deleted_before_locking(): void
    {
        $destination = $this->destination();
        DockerVolume::create(['name' => 'vol_a', 'exists' => true]);
        $this->installDestinationDeletionBeforeLock($destination);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('backup-jobs.store'), $this->payload(['backup_destination_id' => $destination->id]))
            ->assertSessionHasErrors('backup_destination_id');

        $this->assertSame(0, BackupJob::count());
    }

    public function test_api_manual_job_creation_reports_a_destination_deleted_before_locking(): void
    {
        $destination = $this->destination();
        DockerVolume::create(['name' => 'vol_a', 'exists' => true]);
        $this->installDestinationDeletionBeforeLock($destination);
        $token = User::factory()->admin()->create()->createToken('vv', ['read', 'write'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/backup-jobs', $this->payload(['backup_destination_id' => $destination->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('backup_destination_id');

        $this->assertSame(0, BackupJob::count());
    }

    public function test_manual_job_creation_reports_a_volume_deleted_before_locking(): void
    {
        $destination = $this->destination();
        $payload = $this->payload(['backup_destination_id' => $destination->id]);
        $volume = DockerVolume::query()->where('name', 'vol_a')->sole();
        $this->installVolumeDeletionBeforeLock($volume);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('backup-jobs.store'), $payload)
            ->assertSessionHasErrors('volume_name');

        $this->assertSame(0, BackupJob::count());
    }

    public function test_web_inline_group_store_guard_rejection_leaves_no_group_or_activity(): void
    {
        $destination = $this->destination();
        DockerVolume::create(['name' => 'vol_a', 'exists' => true]);
        $this->installDestinationDeletionBeforeLock($destination);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('backup-jobs.store'), $this->inlineGroupPayload($destination))
            ->assertSessionHasErrors('backup_destination_id');

        $this->assertSame(0, BackupJobGroup::count());
        $this->assertSame(0, ActivityLog::where('event_type', 'backup_group_created')->count());
    }

    public function test_api_inline_group_store_rechecks_group_channels_under_lock_without_leaving_a_group(): void
    {
        $destination = $this->destination();
        DockerVolume::create(['name' => 'vol_a', 'exists' => true]);
        $channel = NotificationChannel::create([
            'name' => 'Concurrent channel',
            'service' => NotificationChannel::SERVICE_SMTP,
            'url' => 'mailto://alerts@example.com',
            'notification_level' => NotificationChannel::LEVEL_INFO,
            'is_active' => true,
        ]);
        $this->installNotificationChannelDeletionBeforeLock($channel);
        $token = User::factory()->admin()->create()->createToken('vv', ['read', 'write'])->plainTextToken;
        $payload = $this->inlineGroupPayload($destination);
        $payload['new_group']['notification_channel_ids'] = [$channel->id];

        $this->withToken($token)
            ->postJson('/api/v1/backup-jobs', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('notification_channel_ids');

        $this->assertSame(0, BackupJobGroup::count());
        $this->assertSame(0, ActivityLog::where('event_type', 'backup_group_created')->count());
    }

    public function test_web_inline_group_update_guard_rejection_leaves_job_and_group_state_unchanged(): void
    {
        $job = $this->job('vol_a');
        DockerVolume::create(['name' => 'vol_a', 'exists' => true]);
        $destination = $this->destination();
        $this->installDestinationDeletionBeforeLock($destination);

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('backup-jobs.update', $job), $this->inlineGroupPayload($destination))
            ->assertSessionHasErrors('backup_destination_id');

        $this->assertNull($job->fresh()->backup_job_group_id);
        $this->assertSame(0, BackupJobGroup::count());
        $this->assertSame(0, ActivityLog::where('event_type', 'backup_group_created')->count());
    }

    public function test_api_inline_group_update_guard_rejection_leaves_job_and_group_state_unchanged(): void
    {
        $job = $this->job('vol_a');
        DockerVolume::create(['name' => 'vol_a', 'exists' => true]);
        $destination = $this->destination();
        $this->installDestinationDeletionBeforeLock($destination);
        $token = User::factory()->admin()->create()->createToken('vv', ['read', 'write'])->plainTextToken;

        $this->withToken($token)
            ->putJson("/api/v1/backup-jobs/{$job->id}", $this->inlineGroupPayload($destination))
            ->assertStatus(422)
            ->assertJsonValidationErrors('backup_destination_id');

        $this->assertNull($job->fresh()->backup_job_group_id);
        $this->assertSame(0, BackupJobGroup::count());
        $this->assertSame(0, ActivityLog::where('event_type', 'backup_group_created')->count());
    }

    public function test_web_manual_job_update_rejects_a_pending_managed_volume_under_lock(): void
    {
        $manual = $this->job('vol_a');
        DockerVolume::create(['name' => 'vol_b', 'exists' => true]);
        $managed = $this->job('vol_c');
        $managed->update([
            'configuration_source' => BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL,
            'configuration_key' => hash('sha256', 'managed-vol-c'),
            'pending_label_reconciliation' => [
                'action' => 'apply',
                'payload' => [
                    'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
                    'volume_name' => 'vol_b',
                    'backup_destination_id' => $managed->backup_destination_id,
                ],
                'notification_channel_ids' => [],
            ],
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('backup-jobs.update', $manual), $this->payload([
                'backup_destination_id' => $manual->backup_destination_id,
                'volume_name' => 'vol_b',
            ]))
            ->assertSessionHasErrors('volume_name');

        $this->assertSame('vol_a', $manual->fresh()->volume_name);
    }

    public function test_api_manual_job_update_rejects_a_managed_volume_under_lock(): void
    {
        $manual = $this->job('vol_a');
        DockerVolume::create(['name' => 'vol_b', 'exists' => true]);
        $managed = $this->job('vol_b');
        $managed->update([
            'configuration_source' => BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL,
            'configuration_key' => hash('sha256', 'managed-vol-b'),
        ]);
        $token = User::factory()->admin()->create()->createToken('vv', ['read', 'write'])->plainTextToken;

        $this->withToken($token)
            ->putJson("/api/v1/backup-jobs/{$manual->id}", $this->payload([
                'backup_destination_id' => $manual->backup_destination_id,
                'volume_name' => 'vol_b',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('volume_name');

        $this->assertSame('vol_a', $manual->fresh()->volume_name);
    }

    private function job(string $volume): BackupJob
    {
        return BackupJob::create([
            'name' => 'Job '.$volume,
            'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
            'volume_name' => $volume,
            'backup_destination_id' => $this->destination()->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);
    }

    private function pendingContainerCleanupRun(BackupJob $job): BackupRun
    {
        return BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_FAILED,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'docker_container_id' => 'volumevault-backup-1-secret',
            'docker_container_cleanup_pending' => true,
        ]);
    }

    private function runningRun(BackupJob $job): BackupRun
    {
        return BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_RUNNING,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'started_at' => now(),
        ]);
    }

    private function runningRestore(BackupJob $job): RestoreRun
    {
        return RestoreRun::create([
            'backup_job_id' => $job->id,
            'backup_destination_id' => $job->backup_destination_id,
            'selected_backup_key' => 'backup.tar.gz',
            'source_volume_name' => $job->volume_name,
            'target_volume_name' => $job->volume_name,
            'mode' => RestoreRun::MODE_INPLACE,
            'status' => RestoreRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);
    }

    private function group(string $name): BackupJobGroup
    {
        return BackupJobGroup::create([
            'name' => $name,
            'schedule_type' => BackupJobGroup::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJobGroup::STATUS_ACTIVE,
            'failure_policy' => BackupJobGroup::FAILURE_POLICY_CONTINUE,
            'notifications_enabled' => true,
        ]);
    }

    private function groupRun(BackupJobGroup $group, string $status): BackupGroupRun
    {
        return BackupGroupRun::create([
            'backup_job_group_id' => $group->id,
            'status' => $status,
            'trigger' => BackupGroupRun::TRIGGER_MANUAL,
            'started_at' => $status === BackupGroupRun::STATUS_RUNNING ? now() : null,
        ]);
    }

    private function destination(string $name = 'S3'): BackupDestination
    {
        return BackupDestination::create([
            'name' => $name,
            'provider' => BackupDestination::PROVIDER_AWS_S3,
            'bucket' => 'backups',
            'access_key_id' => 'access',
            'secret_access_key' => 'secret',
            'is_active' => true,
        ]);
    }

    private function restoreReading(
        BackupDestination $source,
        BackupDestination $current,
        string $mode,
        bool $backupBeforeOverwrite = false,
    ): RestoreRun {
        $job = BackupJob::create([
            'name' => 'Historical restore '.$source->id,
            'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
            'volume_name' => 'restore_'.$source->id,
            'backup_destination_id' => $current->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);

        return RestoreRun::create([
            'backup_job_id' => $job->id,
            'backup_destination_id' => $source->id,
            'selected_backup_key' => 'backup.tar.gz',
            'source_volume_name' => $job->volume_name,
            'target_volume_name' => $mode === RestoreRun::MODE_NEW_VOLUME ? $job->volume_name.'_restored' : $job->volume_name,
            'mode' => $mode,
            'backup_before_overwrite' => $backupBeforeOverwrite,
            'status' => RestoreRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);
    }

    private function updateDestination(bool $api, BackupDestination $destination, string $name): TestResponse
    {
        $payload = [
            'name' => $name,
            'provider' => BackupDestination::PROVIDER_AWS_S3,
            'bucket' => 'updated-backups',
            'is_active' => true,
        ];
        $admin = User::factory()->admin()->create();

        if ($api) {
            $this->app['auth']->forgetGuards();
            $this->app['session']->flush();
            $token = $admin->createToken('destination-write', ['read', 'write'])->plainTextToken;

            return $this->withToken($token)->putJson('/api/v1/destinations/'.$destination->id, $payload);
        }

        return $this->actingAs($admin)
            ->from(route('destinations.edit', $destination))
            ->put(route('destinations.update', $destination), $payload);
    }

    private function assertDestinationUpdateBlocked(TestResponse $response, bool $api): void
    {
        if ($api) {
            $response->assertStatus(422)->assertJsonValidationErrors('is_active');

            return;
        }

        $response->assertSessionHasErrors('is_active');
    }

    private function installManagedJobBeforeLock(BackupDestination $destination): void
    {
        $locks = new class($destination) extends WithDockerLabelMutationLocks
        {
            private bool $createdManagedJob = false;

            public function __construct(private readonly BackupDestination $destination) {}

            public function handle(array $destinationIds, callable $callback, array $volumeNames = [], array $notificationChannelIds = [], array $explicitJobIds = []): mixed
            {
                if (! $this->createdManagedJob) {
                    $this->createdManagedJob = true;
                    BackupJob::create([
                        'name' => 'Label managed job',
                        'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
                        'volume_name' => 'vol_a',
                        'backup_destination_id' => $this->destination->id,
                        'schedule_type' => BackupJob::SCHEDULE_DAILY,
                        'schedule_config' => ['time' => '02:00'],
                        'cron_expression' => '0 2 * * *',
                        'status' => BackupJob::STATUS_ACTIVE,
                        'configuration_source' => BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL,
                        'configuration_key' => hash('sha256', 'managed-vol-a'),
                    ]);
                }

                return parent::handle($destinationIds, $callback, $volumeNames, $notificationChannelIds, $explicitJobIds);
            }
        };

        $this->app->instance(WithDockerLabelMutationLocks::class, $locks);
    }

    private function installDestinationDeletionBeforeLock(BackupDestination $destination): void
    {
        $locks = new class($destination) extends WithDockerLabelMutationLocks
        {
            private bool $deleted = false;

            public function __construct(private readonly BackupDestination $destination) {}

            public function handle(array $destinationIds, callable $callback, array $volumeNames = [], array $notificationChannelIds = [], array $explicitJobIds = []): mixed
            {
                if (! $this->deleted) {
                    $this->deleted = true;
                    $this->destination->delete();
                }

                return parent::handle($destinationIds, $callback, $volumeNames, $notificationChannelIds, $explicitJobIds);
            }
        };

        $this->app->instance(WithDockerLabelMutationLocks::class, $locks);
    }

    private function installVolumeDeletionBeforeLock(DockerVolume $volume): void
    {
        $locks = new class($volume) extends WithDockerLabelMutationLocks
        {
            private bool $deleted = false;

            public function __construct(private readonly DockerVolume $volume) {}

            public function handle(array $destinationIds, callable $callback, array $volumeNames = [], array $notificationChannelIds = [], array $explicitJobIds = []): mixed
            {
                if (! $this->deleted) {
                    $this->deleted = true;
                    $this->volume->delete();
                }

                return parent::handle($destinationIds, $callback, $volumeNames, $notificationChannelIds, $explicitJobIds);
            }
        };

        $this->app->instance(WithDockerLabelMutationLocks::class, $locks);
    }

    private function installNotificationChannelDeletionBeforeLock(NotificationChannel $channel): void
    {
        $locks = new class($channel) extends WithDockerLabelMutationLocks
        {
            private bool $deleted = false;

            public function __construct(private readonly NotificationChannel $channel) {}

            public function handle(array $destinationIds, callable $callback, array $volumeNames = [], array $notificationChannelIds = [], array $explicitJobIds = []): mixed
            {
                if (! $this->deleted) {
                    $this->deleted = true;
                    DB::table('notification_channels')->where('id', $this->channel->id)->delete();
                }

                return parent::handle($destinationIds, $callback, $volumeNames, $notificationChannelIds, $explicitJobIds);
            }
        };

        $this->app->instance(WithDockerLabelMutationLocks::class, $locks);
    }

    private function inlineGroupPayload(BackupDestination $destination): array
    {
        return [
            'name' => 'Inline grouped job',
            'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
            'volume_name' => 'vol_a',
            'backup_destination_id' => $destination->id,
            'planning_mode' => 'group',
            'group_selection' => 'new',
            'new_group' => [
                'name' => 'Inline group',
                'schedule_type' => BackupJobGroup::SCHEDULE_DAILY,
                'schedule_config' => ['time' => '02:00'],
                'failure_policy' => BackupJobGroup::FAILURE_POLICY_CONTINUE,
                'notifications_enabled' => true,
            ],
        ];
    }

    private function existingGroupPayload(BackupJob $job, BackupJobGroup $group): array
    {
        return $this->payload([
            'backup_destination_id' => $job->backup_destination_id,
            'volume_name' => $job->volume_name,
            'planning_mode' => 'group',
            'group_selection' => 'existing',
            'backup_job_group_id' => $group->id,
        ]);
    }

    private function standalonePayload(BackupJob $job): array
    {
        return $this->payload([
            'backup_destination_id' => $job->backup_destination_id,
            'volume_name' => $job->volume_name,
            'planning_mode' => 'standalone',
        ]);
    }

    private function updateJob(bool $api, BackupJob $job, array $payload): TestResponse
    {
        $admin = User::factory()->admin()->create();

        if ($api) {
            $this->app['auth']->forgetGuards();
            $this->app['session']->flush();
            $token = $admin->createToken('vv', ['read', 'write'])->plainTextToken;

            return $this->withToken($token)->putJson("/api/v1/backup-jobs/{$job->id}", $payload);
        }

        return $this->actingAs($admin)->put(route('backup-jobs.update', $job), $payload);
    }

    private function createJob(bool $api, array $payload): TestResponse
    {
        $admin = User::factory()->admin()->create();

        if ($api) {
            $this->app['auth']->forgetGuards();
            $this->app['session']->flush();
            $token = $admin->createToken('vv', ['read', 'write'])->plainTextToken;

            return $this->withToken($token)->postJson('/api/v1/backup-jobs', $payload);
        }

        return $this->actingAs($admin)->post(route('backup-jobs.store'), $payload);
    }

    private function assertMembershipError(TestResponse $response, bool $api): void
    {
        if ($api) {
            $response->assertStatus(422)->assertJsonValidationErrors('backup_job_group_id');

            return;
        }

        $response->assertSessionHasErrors('backup_job_group_id');
    }

    private function assertMutationSuccessful(TestResponse $response, bool $api): void
    {
        if ($api) {
            $response->assertOk();

            return;
        }

        $response->assertRedirect(route('backup-jobs.index'))->assertSessionHasNoErrors();
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        $payload = array_merge([
            'name' => 'Job vol_a',
            'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
            'volume_name' => 'vol_a',
            'backup_destination_id' => $this->destination()->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
        ], $overrides);

        if (($payload['source_type'] ?? null) === BackupJob::SOURCE_TYPE_DOCKER_VOLUME
            && is_string($payload['volume_name'] ?? null)) {
            DockerVolume::firstOrCreate(['name' => $payload['volume_name']], ['exists' => true]);
        }

        return $payload;
    }
}
