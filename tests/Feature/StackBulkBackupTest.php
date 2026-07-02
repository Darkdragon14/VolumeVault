<?php

namespace Tests\Feature;

use App\Actions\Backup\BackupStack;
use App\Jobs\RunBackupJob;
use App\Models\BackupDestination;
use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\DockerVolume;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StackBulkBackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_backing_up_a_stack_creates_missing_jobs_and_queues_runs(): void
    {
        Queue::fake();

        $destination = $this->destination();
        $this->volume('app_data', 'app');
        $this->volume('app_logs', 'app');
        $this->job($destination, 'app_data');

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('stacks.index'))
            ->post('/stacks/backup', [
                'stack' => 'app',
                'backup_destination_id' => $destination->id,
                'schedule_type' => BackupJob::SCHEDULE_DAILY,
                'schedule_config' => ['time' => '02:00'],
            ])
            ->assertRedirect(route('stacks.index'))
            ->assertSessionHas('success');

        // A job was created for the uncovered volume, with the chosen schedule.
        $created = BackupJob::where('volume_name', 'app_logs')->first();
        $this->assertNotNull($created);
        $this->assertSame(BackupJob::SCHEDULE_DAILY, $created->schedule_type);
        $this->assertSame('0 2 * * *', $created->cron_expression);

        // One run queued per volume in the stack (existing + created).
        $this->assertSame(2, BackupRun::count());
        Queue::assertPushed(RunBackupJob::class, 2);
    }

    public function test_stack_backup_runs_are_attributed_to_the_authenticated_user(): void
    {
        Queue::fake();

        $destination = $this->destination();
        $this->volume('api_data', 'api');
        $this->job($destination, 'api_data');
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->from(route('stacks.index'))
            ->post('/stacks/backup', ['stack' => 'api'])
            ->assertSessionHas('success');

        $run = BackupRun::firstOrFail();
        $this->assertSame(BackupRun::TRIGGER_MANUAL, $run->trigger);
        $this->assertSame($user->id, $run->initiated_by_user_id);
    }

    public function test_created_job_uses_the_chosen_weekly_schedule(): void
    {
        Queue::fake();

        $destination = $this->destination();
        $this->volume('worker_data', 'worker');

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('stacks.index'))
            ->post('/stacks/backup', [
                'stack' => 'worker',
                'backup_destination_id' => $destination->id,
                'schedule_type' => BackupJob::SCHEDULE_WEEKLY,
                'schedule_config' => ['dayOfWeek' => 'monday', 'time' => '03:00'],
            ])
            ->assertSessionHas('success');

        $created = BackupJob::where('volume_name', 'worker_data')->firstOrFail();
        $this->assertSame(BackupJob::SCHEDULE_WEEKLY, $created->schedule_type);
        $this->assertSame('0 3 * * 1', $created->cron_expression);
    }

    public function test_run_all_jobs_queues_runs_without_a_destination(): void
    {
        Queue::fake();

        $destination = $this->destination();
        $this->volume('api_data', 'api');
        $this->volume('api_cache', 'api');
        $this->job($destination, 'api_data');
        $this->job($destination, 'api_cache');

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('stacks.index'))
            ->post('/stacks/backup', ['stack' => 'api'])
            ->assertSessionHas('success');

        // Fully configured stack: no job created, a run queued for each job.
        $this->assertSame(2, BackupJob::count());
        $this->assertSame(2, BackupRun::count());
        Queue::assertPushed(RunBackupJob::class, 2);
    }

    public function test_unrunnable_jobs_are_skipped_without_aborting_the_batch(): void
    {
        Queue::fake();

        $active = $this->destination();
        $inactive = $this->destination('Inactive', false);
        $this->volume('db_data', 'db');
        $this->volume('db_logs', 'db');
        $this->job($active, 'db_data');
        $this->job($inactive, 'db_logs');

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('stacks.index'))
            ->post('/stacks/backup', ['stack' => 'db'])
            ->assertSessionHas('success');

        // Only the job with an active destination produced a run.
        $this->assertSame(1, BackupRun::count());
        Queue::assertPushed(RunBackupJob::class, 1);
    }

    public function test_destination_is_required_when_the_stack_has_uncovered_volumes(): void
    {
        Queue::fake();

        $this->destination();
        $this->volume('cache_data', 'cache');

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('stacks.index'))
            ->post('/stacks/backup', ['stack' => 'cache'])
            ->assertSessionHasErrors('backup_destination_id');

        $this->assertSame(0, BackupJob::count());
        $this->assertSame(0, BackupRun::count());
        Queue::assertNothingPushed();
    }

    public function test_stack_backup_is_admin_only(): void
    {
        $this->volume('app_data', 'app');

        $this->actingAs(User::factory()->user()->create())
            ->post('/stacks/backup', ['stack' => 'app'])
            ->assertForbidden();
    }

    public function test_api_admin_write_token_can_back_up_a_stack(): void
    {
        Queue::fake();

        $destination = $this->destination();
        $this->volume('app_data', 'app');
        $this->volume('app_logs', 'app');
        $this->job($destination, 'app_data');

        $token = User::factory()->admin()->create()->createToken('vv', ['read', 'write'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/stacks/backup', [
                'stack' => 'app',
                'backup_destination_id' => $destination->id,
                'schedule_type' => BackupJob::SCHEDULE_DAILY,
                'schedule_config' => ['time' => '02:00'],
            ])
            ->assertAccepted()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.queued', 2)
            ->assertJsonPath('data.skipped', 0);

        $this->assertNotNull(BackupJob::where('volume_name', 'app_logs')->first());
        Queue::assertPushed(RunBackupJob::class, 2);
    }

    public function test_api_stack_backup_rejects_a_read_only_token(): void
    {
        $token = User::factory()->admin()->create()->createToken('vv', ['read'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/stacks/backup', ['stack' => 'app'])
            ->assertForbidden();
    }

    public function test_api_stack_backup_is_admin_only(): void
    {
        $token = User::factory()->user()->create()->createToken('vv', ['read', 'write'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/stacks/backup', ['stack' => 'app'])
            ->assertForbidden();
    }

    public function test_stack_backup_reports_grouped_volumes_without_running_them(): void
    {
        Queue::fake();

        $this->volume('grouped_vol', 'mystack');

        $group = BackupJobGroup::create([
            'name' => 'Group',
            'schedule_type' => BackupJobGroup::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJobGroup::STATUS_ACTIVE,
            'failure_policy' => BackupJobGroup::FAILURE_POLICY_CONTINUE,
            'next_run_at' => now()->addDay(),
        ]);
        BackupJob::create([
            'name' => 'Member',
            'backup_job_group_id' => $group->id,
            'volume_name' => 'grouped_vol',
            'backup_destination_id' => $this->destination()->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
            'next_run_at' => null,
        ]);

        // A grouped volume is reported as grouped, not run: the stack backup must
        // not run it standalone nor trigger the whole group (which may span other
        // stacks). It backs up on the group's own schedule.
        $summary = app(BackupStack::class)->handle('mystack', []);

        $this->assertSame(1, $summary['grouped']);
        $this->assertSame(0, $summary['queued']);
        Queue::assertNothingPushed();
        $this->assertSame(0, BackupGroupRun::where('backup_job_group_id', $group->id)->count());
    }

    private function destination(string $name = 'S3', bool $active = true): BackupDestination
    {
        return BackupDestination::create([
            'name' => $name,
            'provider' => BackupDestination::PROVIDER_AWS_S3,
            'bucket' => 'backups',
            'access_key_id' => 'access',
            'secret_access_key' => 'secret',
            'is_active' => $active,
        ]);
    }

    private function volume(string $name, string $stack): DockerVolume
    {
        return DockerVolume::create([
            'name' => $name,
            'exists' => true,
            'labels' => ['com.docker.compose.project' => $stack],
        ]);
    }

    private function job(BackupDestination $destination, string $volumeName): BackupJob
    {
        return BackupJob::create([
            'name' => 'Backup '.$volumeName,
            'volume_name' => $volumeName,
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);
    }
}
