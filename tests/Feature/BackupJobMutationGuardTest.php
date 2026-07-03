<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupJobMutationGuardTest extends TestCase
{
    use RefreshDatabase;

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

    private function runningRun(BackupJob $job): BackupRun
    {
        return BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_RUNNING,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'started_at' => now(),
        ]);
    }

    private function destination(): BackupDestination
    {
        return BackupDestination::create([
            'name' => 'S3',
            'provider' => BackupDestination::PROVIDER_AWS_S3,
            'bucket' => 'backups',
            'access_key_id' => 'access',
            'secret_access_key' => 'secret',
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Job vol_a',
            'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
            'volume_name' => 'vol_a',
            'backup_destination_id' => $this->destination()->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
        ], $overrides);
    }
}
