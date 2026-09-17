<?php

namespace Tests\Feature;

use App\Jobs\RunBackupGroupJob;
use App\Models\ActivityLog;
use App\Models\BackupDestination;
use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\DockerVolume;
use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class GroupedBackupApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_openapi_document_lists_the_backup_group_endpoints(): void
    {
        $document = $this->getJson('/api/v1/openapi.json')->assertOk()->json();
        $paths = $document['paths'];

        $this->assertArrayHasKey('/backup-groups', $paths);
        $this->assertArrayHasKey('/backup-groups/{id}', $paths);
        $this->assertArrayHasKey('/backup-groups/{id}/run', $paths);
        $this->assertArrayHasKey('/backup-group-runs', $paths);

        // The BackupGroupRun schema documents the aggregated member archive size.
        $this->assertArrayHasKey('total_backup_size_bytes', $document['components']['schemas']['BackupGroupRun']['properties']);
    }

    public function test_admin_write_token_can_create_a_backup_group(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('grp-write', ['read', 'write'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/backup-groups', [
                'name' => 'Nightly API group',
                'schedule_type' => 'daily',
                'schedule_config' => ['time' => '02:00'],
                'failure_policy' => 'continue',
                'notifications_enabled' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Nightly API group')
            ->assertJsonPath('data.failure_policy', 'continue');

        $this->assertDatabaseHas('backup_job_groups', ['name' => 'Nightly API group']);
    }

    public function test_api_group_creation_revalidates_channels_and_rolls_back_the_group(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('grp-write', ['read', 'write'])->plainTextToken;
        $channel = $this->notificationChannel('Deleted before API group channel lock');
        Event::listen('eloquent.created: '.BackupJobGroup::class, function () use ($channel): void {
            DB::table('notification_channels')->where('id', $channel->id)->delete();
        });

        $this->withToken($token)
            ->postJson('/api/v1/backup-groups', [
                'name' => 'Rolled back API group',
                'schedule_type' => BackupJobGroup::SCHEDULE_DAILY,
                'schedule_config' => ['time' => '02:00'],
                'failure_policy' => BackupJobGroup::FAILURE_POLICY_CONTINUE,
                'notification_channel_ids' => [$channel->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('notification_channel_ids');

        $this->assertSame(0, BackupJobGroup::count());
        $this->assertSame(0, ActivityLog::where('event_type', 'backup_group_created')->count());
        $this->assertDatabaseHas('notification_channels', ['id' => $channel->id]);
    }

    public function test_read_token_can_list_groups_but_not_create(): void
    {
        $this->group();
        $admin = User::factory()->admin()->create();
        $readToken = $admin->createToken('grp-read', ['read'])->plainTextToken;

        $this->withToken($readToken)->getJson('/api/v1/backup-groups')->assertOk()->assertJsonCount(1, 'data');

        $this->withToken($readToken)
            ->postJson('/api/v1/backup-groups', ['name' => 'x', 'schedule_type' => 'daily', 'failure_policy' => 'continue'])
            ->assertForbidden();
    }

    public function test_non_admin_write_token_cannot_manage_groups(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('grp-write', ['read', 'write'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/backup-groups', ['name' => 'x', 'schedule_type' => 'daily', 'failure_policy' => 'continue'])
            ->assertForbidden();
    }

    public function test_an_invalid_planning_mode_is_rejected_not_coerced_to_standalone(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('grp-write', ['read', 'write'])->plainTextToken;
        $destination = $this->destination();

        // A typo must fail the enum, not silently create a standalone job.
        $this->withToken($token)
            ->postJson('/api/v1/backup-jobs', [
                'name' => 'Typo mode',
                'source_type' => 'docker_volume',
                'volume_name' => 'api_vol',
                'backup_destination_id' => $destination->id,
                'planning_mode' => 'gruop',
                'schedule_type' => 'daily',
                'schedule_config' => ['time' => '02:00'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('planning_mode');

        $this->assertNull(BackupJob::firstWhere('name', 'Typo mode'));
    }

    public function test_a_job_can_be_attached_to_a_group_via_the_api(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('grp-write', ['read', 'write'])->plainTextToken;
        $destination = $this->destination();
        $group = $this->group();
        DockerVolume::create(['name' => 'api_vol', 'exists' => true]);

        $this->withToken($token)
            ->postJson('/api/v1/backup-jobs', [
                'name' => 'Grouped via API',
                'source_type' => 'docker_volume',
                'volume_name' => 'api_vol',
                'backup_destination_id' => $destination->id,
                'planning_mode' => 'group',
                'group_selection' => 'existing',
                'backup_job_group_id' => $group->id,
            ])
            ->assertCreated();

        $job = BackupJob::firstWhere('name', 'Grouped via API');
        $this->assertSame($group->id, $job->backup_job_group_id);
        $this->assertNull($job->next_run_at);
    }

    public function test_admin_write_token_can_queue_a_group_run(): void
    {
        config(['queue.default' => 'database']);
        Bus::fake([RunBackupGroupJob::class]);

        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('grp-write', ['read', 'write'])->plainTextToken;
        $group = $this->group();
        $this->member($group);

        $response = $this->withToken($token)
            ->postJson("/api/v1/backup-groups/{$group->id}/run")
            ->assertStatus(202)
            // A freshly queued run has no member sizes yet: the documented aggregate
            // key must be present and null, not omitted from the payload.
            ->assertJsonPath('data.total_backup_size_bytes', null);

        $this->assertArrayHasKey('total_backup_size_bytes', $response->json('data'));

        Bus::assertDispatched(RunBackupGroupJob::class);
        $this->assertSame(1, $group->groupRuns()->count());
    }

    public function test_deleting_a_group_with_members_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('grp-write', ['read', 'write'])->plainTextToken;
        $group = $this->group();
        $this->member($group);

        $this->withToken($token)
            ->deleteJson("/api/v1/backup-groups/{$group->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('backup_job_groups', ['id' => $group->id]);
    }

    public function test_resuming_a_job_via_the_api_clears_the_error_timestamp(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('job-write', ['read', 'write'])->plainTextToken;
        $job = BackupJob::create([
            'name' => 'Errored',
            'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
            'volume_name' => 'vol_a',
            'backup_destination_id' => $this->destination()->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ERROR,
            'last_error' => 'boom',
            'last_error_at' => now()->subHour(),
        ]);

        $this->withToken($token)
            ->postJson("/api/v1/backup-jobs/{$job->id}/resume")
            ->assertOk();

        $fresh = $job->fresh();
        $this->assertSame(BackupJob::STATUS_ACTIVE, $fresh->status);
        // Both the message and its timestamp must be cleared, not just the message.
        $this->assertNull($fresh->last_error);
        $this->assertNull($fresh->last_error_at);
    }

    public function test_deleting_a_group_with_an_active_run_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('grp-write', ['read', 'write'])->plainTextToken;
        $group = $this->group(); // no members
        BackupGroupRun::create([
            'backup_job_group_id' => $group->id,
            'status' => BackupGroupRun::STATUS_RUNNING,
            'trigger' => BackupGroupRun::TRIGGER_MANUAL,
            'started_at' => now(),
        ]);

        // A run in flight would be cascade-deleted, losing its finalization/history.
        $this->withToken($token)
            ->deleteJson("/api/v1/backup-groups/{$group->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('backup_job_groups', ['id' => $group->id]);
    }

    public function test_toggling_notifications_requires_the_flag(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('grp-write', ['read', 'write'])->plainTextToken;
        $group = $this->group();

        // An empty/typo'd payload must be rejected, not silently disable monitoring.
        $this->withToken($token)
            ->patchJson("/api/v1/backup-groups/{$group->id}/notifications", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('notifications_enabled');

        $this->assertTrue($group->fresh()->notifications_enabled);
    }

    public function test_toggling_notifications_applies_the_flag(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('grp-write', ['read', 'write'])->plainTextToken;
        $group = $this->group();

        $this->withToken($token)
            ->patchJson("/api/v1/backup-groups/{$group->id}/notifications", ['notifications_enabled' => false])
            ->assertOk();

        $this->assertFalse($group->fresh()->notifications_enabled);
    }

    public function test_api_group_update_uses_locked_lifecycle_state_and_preserves_omitted_notifications(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('grp-write', ['read', 'write'])->plainTextToken;
        $group = $this->group();
        $member = $this->member($group);
        $channel = $this->notificationChannel('Existing API channel');
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
                'status' => BackupJobGroup::STATUS_ERROR,
                'pause_reason' => 'API lifecycle marker',
                'last_run_at' => $lifecycleAt,
                'last_success_at' => $lifecycleAt,
                'last_error' => 'API worker marker',
                'last_error_at' => $lifecycleAt,
            ]);
        });

        $this->withToken($token)
            ->putJson("/api/v1/backup-groups/{$group->id}", [
                'name' => 'Locked API update',
                'schedule_type' => BackupJobGroup::SCHEDULE_DAILY,
                'schedule_config' => ['time' => '05:00'],
                'failure_policy' => BackupJobGroup::FAILURE_POLICY_STOP,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', BackupJobGroup::STATUS_ERROR)
            ->assertJsonPath('data.notifications_enabled', false);

        $freshGroup = $group->fresh();
        $this->assertSame(BackupJobGroup::STATUS_ERROR, $freshGroup->status);
        $this->assertSame('API lifecycle marker', $freshGroup->pause_reason);
        $this->assertSame('API worker marker', $freshGroup->last_error);
        $this->assertTrue($freshGroup->last_run_at->equalTo($lifecycleAt));
        $this->assertTrue($freshGroup->last_success_at->equalTo($lifecycleAt));
        $this->assertTrue($freshGroup->last_error_at->equalTo($lifecycleAt));
        $this->assertSame([$channel->id], $freshGroup->notificationChannels()->pluck('notification_channels.id')->all());
        $this->assertSame(['time' => '05:00'], $member->fresh()->schedule_config);
        $this->assertSame('0 5 * * *', $member->fresh()->cron_expression);
    }

    public function test_api_group_update_rolls_back_config_channels_and_member_propagation_together(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('grp-write', ['read', 'write'])->plainTextToken;
        $group = $this->group();
        $member = $this->member($group);
        $existingChannel = $this->notificationChannel('Existing API rollback channel');
        $replacementChannel = $this->notificationChannel('Replacement API rollback channel');
        $group->forceFill(['notifications_enabled' => false])->save();
        $group->notificationChannels()->attach($existingChannel);
        $this->failAfterMemberScheduleUpdate();
        $this->withoutExceptionHandling();

        try {
            $this->withToken($token)->putJson("/api/v1/backup-groups/{$group->id}", [
                'name' => 'Must roll back via API',
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

    public function test_showing_a_group_includes_members_and_recent_group_runs(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('grp-read', ['read'])->plainTextToken;
        $group = $this->group();
        $member = $this->member($group);
        $groupRun = BackupGroupRun::create([
            'backup_job_group_id' => $group->id,
            'status' => BackupGroupRun::STATUS_SUCCESS,
            'trigger' => BackupGroupRun::TRIGGER_MANUAL,
            'scheduled_for' => '2026-09-09 02:00:00',
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
            'total_members' => 1,
            'succeeded_members' => 1,
        ]);
        BackupRun::create([
            'backup_job_id' => $member->id,
            'backup_group_run_id' => $groupRun->id,
            'status' => BackupRun::STATUS_SUCCESS,
            'trigger' => BackupRun::TRIGGER_MANUAL,
            'backup_size_bytes' => 4096,
        ]);

        $this->withToken($token)
            ->getJson("/api/v1/backup-groups/{$group->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['members', 'recent_group_runs']])
            ->assertJsonCount(1, 'data.recent_group_runs')
            ->assertJsonPath('data.recent_group_runs.0.scheduled_for', '2026-09-09T02:00:00.000000Z')
            ->assertJsonPath('data.recent_group_runs.0.total_backup_size_bytes', 4096);
    }

    public function test_group_run_endpoints_report_the_aggregated_size(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('grp-read', ['read'])->plainTextToken;
        $group = $this->group();
        $member = $this->member($group);
        $groupRun = BackupGroupRun::create([
            'backup_job_group_id' => $group->id,
            'status' => BackupGroupRun::STATUS_SUCCESS,
            'trigger' => BackupGroupRun::TRIGGER_MANUAL,
            'scheduled_for' => '2026-09-09 02:00:00',
            'total_members' => 1,
            'succeeded_members' => 1,
        ]);
        BackupRun::create([
            'backup_job_id' => $member->id,
            'backup_group_run_id' => $groupRun->id,
            'status' => BackupRun::STATUS_SUCCESS,
            'trigger' => BackupRun::TRIGGER_MANUAL,
            'scheduled_for' => '2026-09-09 02:00:00',
            'source_type_snapshot' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
            'source_volume_name' => 'historical_member_vol',
            'backup_size_bytes' => 4096,
        ]);
        $member->update(['volume_name' => 'retargeted_member_vol']);

        $this->withToken($token)
            ->getJson('/api/v1/backup-group-runs')
            ->assertOk()
            ->assertJsonPath('data.0.total_backup_size_bytes', 4096);

        $this->withToken($token)
            ->getJson("/api/v1/backup-group-runs/{$groupRun->id}")
            ->assertOk()
            ->assertJsonPath('data.total_backup_size_bytes', 4096)
            ->assertJsonPath('data.members.0.source_label', 'historical_member_vol')
            ->assertJsonPath('data.members.0.scheduled_for', '2026-09-09T02:00:00.000000Z');
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
            'url' => 'ntfy://ntfy.sh/backup-group-api-test',
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
}
