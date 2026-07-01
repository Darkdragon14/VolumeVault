<?php

namespace Tests\Feature;

use App\Jobs\RunBackupGroupJob;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class GroupedBackupApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_openapi_document_lists_the_backup_group_endpoints(): void
    {
        $paths = $this->getJson('/api/v1/openapi.json')->assertOk()->json('paths');

        $this->assertArrayHasKey('/backup-groups', $paths);
        $this->assertArrayHasKey('/backup-groups/{id}', $paths);
        $this->assertArrayHasKey('/backup-groups/{id}/run', $paths);
        $this->assertArrayHasKey('/backup-group-runs', $paths);
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

        $this->withToken($token)
            ->postJson("/api/v1/backup-groups/{$group->id}/run")
            ->assertStatus(202);

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
