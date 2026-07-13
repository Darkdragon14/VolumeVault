<?php

namespace Tests\Feature;

use App\Jobs\RunBackupGroupJob;
use App\Models\BackupDestination;
use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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
            ->getJson('/api/v1/backup-group-runs')
            ->assertOk()
            ->assertJsonPath('data.0.total_backup_size_bytes', 4096);

        $this->withToken($token)
            ->getJson("/api/v1/backup-group-runs/{$groupRun->id}")
            ->assertOk()
            ->assertJsonPath('data.total_backup_size_bytes', 4096);
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
}
