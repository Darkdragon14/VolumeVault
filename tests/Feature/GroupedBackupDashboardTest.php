<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GroupedBackupDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFixtures();
    }

    public function test_web_dashboard_counts_groups_separately_and_excludes_member_jobs(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                // One standalone job; the two group members are not counted as jobs.
                ->where('stats.total_jobs', 1)
                ->where('stats.total_groups', 1)
                ->where('stats.error_groups', 1)
                ->where('stats.active_groups', 0)
                // Only the standalone run; the member run is excluded.
                ->has('recentBackupRuns', 1)
                ->has('recentGroupRuns', 1)
                ->has('groupsWithErrors', 1)
                ->etc()
            );
    }

    public function test_api_dashboard_mirrors_group_stats_and_exclusions(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('dash-read', ['read'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.stats.total_jobs', 1)
            ->assertJsonPath('data.stats.total_groups', 1)
            ->assertJsonPath('data.stats.error_groups', 1)
            ->assertJsonCount(1, 'data.recent_backup_runs')
            ->assertJsonCount(1, 'data.recent_group_runs')
            ->assertJsonCount(1, 'data.groups_with_errors');
    }

    private function seedFixtures(): void
    {
        $destination = BackupDestination::create([
            'name' => 'Local',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'is_active' => true,
            'settings' => ['archive_path' => sys_get_temp_dir().'/vv'],
        ]);

        $standalone = BackupJob::create([
            'name' => 'Standalone',
            'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
            'volume_name' => 'vol_solo',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
            'next_run_at' => now()->addDay(),
        ]);

        $group = BackupJobGroup::create([
            'name' => 'Group',
            'schedule_type' => BackupJobGroup::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJobGroup::STATUS_ERROR,
            'failure_policy' => BackupJobGroup::FAILURE_POLICY_CONTINUE,
            'last_error' => '1 of 2 volume(s) failed to back up.',
            'next_run_at' => now()->addDay(),
        ]);

        foreach (['vol_a', 'vol_b'] as $volume) {
            BackupJob::create([
                'name' => 'Member '.$volume,
                'backup_job_group_id' => $group->id,
                'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
                'volume_name' => $volume,
                'backup_destination_id' => $destination->id,
                'schedule_type' => BackupJob::SCHEDULE_DAILY,
                'schedule_config' => ['time' => '02:00'],
                'cron_expression' => '0 2 * * *',
                'status' => BackupJob::STATUS_ACTIVE,
                'next_run_at' => null,
            ]);
        }

        $groupRun = BackupGroupRun::create([
            'backup_job_group_id' => $group->id,
            'status' => BackupGroupRun::STATUS_FAILED,
            'trigger' => BackupGroupRun::TRIGGER_SCHEDULED,
            'total_members' => 2,
            'succeeded_members' => 1,
            'failed_members' => 1,
        ]);

        // A standalone run (counted) and a member run (excluded from recent backups).
        BackupRun::create([
            'backup_job_id' => $standalone->id,
            'status' => BackupRun::STATUS_SUCCESS,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
        ]);
        BackupRun::create([
            'backup_job_id' => $group->members()->first()->id,
            'backup_group_run_id' => $groupRun->id,
            'status' => BackupRun::STATUS_FAILED,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
        ]);
    }
}
