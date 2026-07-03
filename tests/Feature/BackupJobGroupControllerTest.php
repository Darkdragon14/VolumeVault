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

class BackupJobGroupControllerTest extends TestCase
{
    use RefreshDatabase;

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
        Bus::fake([RunBackupGroupJob::class]);

        $group = $this->group();
        $this->member($group);

        $this->actingAs($this->admin())
            ->post(route('backup-groups.run', $group))
            ->assertRedirect();

        Bus::assertDispatched(RunBackupGroupJob::class);
        $this->assertSame(1, $group->groupRuns()->count());
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

    public function test_group_edit_requires_admin(): void
    {
        $group = $this->group();

        $this->actingAs(User::factory()->create())
            ->get(route('backup-groups.edit', $group))
            ->assertForbidden();
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
}
