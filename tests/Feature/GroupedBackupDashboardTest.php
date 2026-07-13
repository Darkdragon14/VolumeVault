<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\User;
use Carbon\CarbonInterface;
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
                // Reflects the standalone success, not the failed member run.
                ->where('stats.last_backup_run_status', 'success')
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
            ->assertJsonPath('data.stats.last_backup_run_status', 'success')
            ->assertJsonCount(1, 'data.recent_backup_runs')
            ->assertJsonCount(1, 'data.recent_group_runs')
            ->assertJsonCount(1, 'data.groups_with_errors');
    }

    public function test_web_dashboard_reports_the_latest_successful_group_backup_size(): void
    {
        $this->seedGroupOnlyScenario();

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                // The most recent successful group run wins; the null member size does
                // not zero the sum, and the seeded FAILED group run is ignored.
                ->where('stats.last_successful_group_backup_size', 3072)
                // The standalone widget is untouched: no standalone success remains.
                ->where('stats.last_successful_backup_size', null)
                ->etc()
            );
    }

    public function test_group_backup_size_is_null_not_zero_when_no_member_size_is_known(): void
    {
        $this->successfulGroupRun([null, null], now());

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                // SUM over all-null member sizes is null on SQLite, never coerced to 0.
                ->where('stats.last_successful_group_backup_size', null)
                ->etc()
            );
    }

    public function test_recent_group_runs_expose_the_aggregated_size(): void
    {
        // addMinute, not now(): a created_at equal to the second to the seeded FAILED
        // run would make the latest() ordering non-deterministic on SQLite.
        $this->successfulGroupRun([1024, 2048], now()->addMinute());

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('recentGroupRuns.0.total_backup_size_bytes', 3072)
                // The seeded FAILED run has no recorded member size → stays null.
                ->where('recentGroupRuns.1.total_backup_size_bytes', null)
                ->etc()
            );
    }

    public function test_api_dashboard_mirrors_the_group_backup_size(): void
    {
        $this->seedGroupOnlyScenario();

        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('dash-read', ['read'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.stats.last_successful_group_backup_size', 3072)
            ->assertJsonPath('data.recent_group_runs.0.total_backup_size_bytes', 3072);
    }

    /**
     * The issue's scenario: a group-only user. Drops the standalone success (so the
     * standalone widget reads null) and records an older and a newer successful group
     * run. The newer one finishes in the future so it also sorts ahead of the seeded
     * FAILED run (created "now") in the recent-runs list.
     */
    private function seedGroupOnlyScenario(): void
    {
        BackupRun::query()->whereNull('backup_group_run_id')->delete();

        $this->successfulGroupRun([9999], now()->subDay());
        $this->successfulGroupRun([1024, 2048, null], now()->addMinute());
    }

    /**
     * Record a successful group run whose member runs carry the given archive sizes
     * (null accepted). created_at is pinned to $finishedAt because it is not fillable
     * and SQLite's one-second datetime resolution makes latest() ties unstable.
     *
     * @param  list<int|null>  $memberSizes
     */
    private function successfulGroupRun(array $memberSizes, CarbonInterface $finishedAt): BackupGroupRun
    {
        $group = BackupJobGroup::firstWhere('name', 'Group');
        $member = $group->members()->first();

        $run = BackupGroupRun::create([
            'backup_job_group_id' => $group->id,
            'status' => BackupGroupRun::STATUS_SUCCESS,
            'trigger' => BackupGroupRun::TRIGGER_SCHEDULED,
            'finished_at' => $finishedAt,
            'total_members' => count($memberSizes),
            'succeeded_members' => count($memberSizes),
            'failed_members' => 0,
        ]);
        $run->forceFill(['created_at' => $finishedAt])->save();

        foreach ($memberSizes as $size) {
            BackupRun::create([
                'backup_job_id' => $member->id,
                'backup_group_run_id' => $run->id,
                'status' => BackupRun::STATUS_SUCCESS,
                'trigger' => BackupRun::TRIGGER_SCHEDULED,
                'backup_size_bytes' => $size,
            ]);
        }

        return $run;
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
