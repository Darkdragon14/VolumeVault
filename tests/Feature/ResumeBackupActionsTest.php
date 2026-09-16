<?php

namespace Tests\Feature;

use App\Actions\Backup\ResumeBackupJob;
use App\Actions\Backup\ResumeBackupJobGroup;
use App\Actions\Backup\WithBackupGroupMutationLocks;
use App\Actions\Backup\WithDockerLabelMutationLocks;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\DockerVolume;
use App\Models\User;
use App\Services\Scheduling\BackupScheduleCalculator;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ResumeBackupActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_job_resume_uses_the_global_lock_order(): void
    {
        $group = $this->group();
        $job = $this->job($this->destination(), [
            'backup_job_group_id' => $group->id,
            'status' => BackupJob::STATUS_PAUSED,
        ]);
        DockerVolume::create(['name' => $job->volume_name, 'exists' => true]);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        app(ResumeBackupJob::class)->handle($job);

        $groupLock = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from "backup_job_groups"') && str_contains($sql, 'order by "id" asc'));
        $destinationLock = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from "backup_destinations"') && str_contains($sql, 'order by "id" asc'));
        $settingsLock = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from "docker_label_backup_settings"'));
        $volumeLock = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from "docker_volumes"') && str_contains($sql, 'order by "name" asc'));
        $jobLock = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from "backup_jobs"') && str_contains($sql, 'order by "id" asc'));

        $this->assertIsInt($groupLock);
        $this->assertIsInt($destinationLock);
        $this->assertIsInt($settingsLock);
        $this->assertIsInt($volumeLock);
        $this->assertIsInt($jobLock);
        $this->assertLessThan($destinationLock, $groupLock);
        $this->assertLessThan($settingsLock, $destinationLock);
        $this->assertLessThan($volumeLock, $settingsLock);
        $this->assertLessThan($jobLock, $volumeLock);
    }

    public function test_job_resume_retries_changed_references_and_uses_the_new_locked_group_schedule(): void
    {
        Carbon::setTestNow('2026-09-03 12:00:00 UTC');
        $originalGroup = $this->group(['name' => 'Original']);
        $replacementGroup = $this->group([
            'name' => 'Replacement',
            'schedule_config' => ['time' => '07:00'],
            'cron_expression' => '0 7 * * *',
            'status' => BackupJobGroup::STATUS_ERROR,
        ]);
        $originalDestination = $this->destination('Original');
        $replacementDestination = $this->destination('Replacement');
        $job = $this->job($originalDestination, [
            'backup_job_group_id' => $originalGroup->id,
            'status' => BackupJob::STATUS_ERROR,
        ]);
        $groupLocks = new class($job, $replacementGroup, $replacementDestination) extends WithBackupGroupMutationLocks
        {
            public array $requests = [];

            private bool $changed = false;

            public function __construct(
                private readonly BackupJob $job,
                private readonly BackupJobGroup $replacementGroup,
                private readonly BackupDestination $replacementDestination,
            ) {}

            public function handle(array $groupIds, callable $callback): mixed
            {
                $this->requests[] = $groupIds;

                if (! $this->changed) {
                    $this->changed = true;
                    $this->job->newQuery()->whereKey($this->job->id)->update([
                        'backup_job_group_id' => $this->replacementGroup->id,
                        'backup_destination_id' => $this->replacementDestination->id,
                        'volume_name' => 'replacement_volume',
                    ]);
                }

                return parent::handle($groupIds, $callback);
            }
        };
        $dockerLocks = new class extends WithDockerLabelMutationLocks
        {
            public array $destinationRequests = [];

            public function handleForJobs(array $managedJobIds, array $destinationIds, callable $callback, array $volumeNames = [], array $notificationChannelIds = [], array $explicitJobIds = []): mixed
            {
                $this->destinationRequests[] = $destinationIds;

                return parent::handleForJobs($managedJobIds, $destinationIds, $callback, $volumeNames, $notificationChannelIds, $explicitJobIds);
            }
        };
        $action = new ResumeBackupJob(app(BackupScheduleCalculator::class), $groupLocks, $dockerLocks);

        $action->handle($job);

        $this->assertSame([[$originalGroup->id], [$replacementGroup->id]], $groupLocks->requests);
        $this->assertSame([[$originalDestination->id], [$replacementDestination->id]], $dockerLocks->destinationRequests);
        $this->assertSame($replacementGroup->id, $job->fresh()->backup_job_group_id);
        $this->assertNull($job->fresh()->next_run_at);
        $this->assertSame(BackupJobGroup::STATUS_ACTIVE, $replacementGroup->fresh()->status);
        $this->assertTrue($replacementGroup->fresh()->next_run_at->equalTo(Carbon::parse('2026-09-04 07:00:00 UTC')));
    }

    public function test_standalone_resume_uses_the_reloaded_job_schedule(): void
    {
        Carbon::setTestNow('2026-09-03 12:00:00 UTC');
        $job = $this->job($this->destination(), ['status' => BackupJob::STATUS_PAUSED]);
        $job->newQuery()->whereKey($job->id)->update([
            'schedule_config' => json_encode(['time' => '06:30']),
            'cron_expression' => '30 6 * * *',
        ]);

        app(ResumeBackupJob::class)->handle($job);

        $this->assertTrue($job->fresh()->next_run_at->equalTo(Carbon::parse('2026-09-04 06:30:00 UTC')));
    }

    public function test_job_resume_only_accepts_paused_or_error_for_every_configuration_source(): void
    {
        foreach ([BackupJob::CONFIGURATION_SOURCE_MANUAL, BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL] as $configurationSource) {
            foreach ([BackupJob::STATUS_PAUSED, BackupJob::STATUS_ERROR] as $status) {
                $job = $this->job($this->destination("{$configurationSource}-{$status}"), [
                    'name' => "{$configurationSource}-{$status}",
                    'volume_name' => "{$configurationSource}_{$status}",
                    'status' => $status,
                    'configuration_source' => $configurationSource,
                    'configuration_key' => $configurationSource === BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL
                        ? hash('sha256', "{$configurationSource}-{$status}")
                        : null,
                ]);

                app(ResumeBackupJob::class)->handle($job);

                $this->assertSame(BackupJob::STATUS_ACTIVE, $job->fresh()->status);
            }
        }
    }

    public function test_group_resume_accepts_paused_and_error(): void
    {
        foreach ([BackupJobGroup::STATUS_PAUSED, BackupJobGroup::STATUS_ERROR] as $status) {
            $group = $this->group([
                'name' => "Group {$status}",
                'status' => $status,
            ]);

            app(ResumeBackupJobGroup::class)->handle($group);

            $this->assertSame(BackupJobGroup::STATUS_ACTIVE, $group->fresh()->status);
        }
    }

    public function test_job_resume_rejects_active_and_unknown_statuses_for_every_ownership_type_without_changes(): void
    {
        $group = $this->group([
            'name' => 'Owned group',
            'status' => BackupJobGroup::STATUS_ERROR,
            'last_error' => 'preserve group error',
            'last_error_at' => now()->subHour(),
            'next_run_at' => now()->subDay(),
        ]);
        $groupBefore = $group->only(['status', 'last_error', 'last_error_at', 'next_run_at']);
        $ownershipTypes = [
            'standalone' => [],
            'grouped' => ['backup_job_group_id' => $group->id],
            'docker-label' => [
                'configuration_source' => BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL,
            ],
        ];

        foreach ($ownershipTypes as $ownershipType => $ownershipAttributes) {
            foreach ([BackupJob::STATUS_ACTIVE, 'unknown'] as $status) {
                $job = $this->job($this->destination("{$ownershipType}-{$status}"), array_merge($ownershipAttributes, [
                    'name' => "{$ownershipType}-{$status}",
                    'volume_name' => "{$ownershipType}_{$status}",
                    'status' => $status,
                    'configuration_key' => $ownershipType === 'docker-label'
                        ? hash('sha256', "non-resumable-managed-{$status}")
                        : null,
                    'pause_reason' => 'preserve pause reason',
                    'last_error' => 'preserve error',
                    'last_error_at' => now()->subHour(),
                    'next_run_at' => now()->subDay(),
                ]));
                $before = $job->only(['status', 'pause_reason', 'last_error', 'last_error_at', 'next_run_at']);

                try {
                    app(ResumeBackupJob::class)->handle($job);
                    $this->fail("The {$ownershipType} {$status} job must not be resumed.");
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('job', $exception->errors());
                }

                $this->assertEquals($before, $job->fresh()->only(array_keys($before)));
                $this->assertEquals($groupBefore, $group->fresh()->only(array_keys($groupBefore)));
            }
        }
    }

    public function test_group_resume_rejects_active_and_unknown_statuses_without_changes(): void
    {
        foreach ([BackupJobGroup::STATUS_ACTIVE, 'unknown'] as $status) {
            $group = $this->group([
                'name' => "Group {$status}",
                'status' => $status,
                'pause_reason' => 'preserve pause reason',
                'last_error' => 'preserve error',
                'last_error_at' => now()->subHour(),
                'next_run_at' => now()->subDay(),
            ]);
            $before = $group->only(['status', 'pause_reason', 'last_error', 'last_error_at', 'next_run_at']);

            try {
                app(ResumeBackupJobGroup::class)->handle($group);
                $this->fail("The {$status} group must not be resumed.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('group', $exception->errors());
            }

            $this->assertEquals($before, $group->fresh()->only(array_keys($before)));
        }
    }

    public function test_job_resume_validates_the_reloaded_locked_state(): void
    {
        $job = $this->job($this->destination(), [
            'status' => BackupJob::STATUS_PAUSED,
            'configuration_source' => BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL,
            'configuration_key' => hash('sha256', 'resume-locked-state'),
        ]);
        $staleValidJob = $job->fresh();
        $job->forceFill([
            'pending_label_reconciliation' => ['action' => 'disable', 'message' => 'Definition removed.'],
        ])->save();

        try {
            app(ResumeBackupJob::class)->handle($staleValidJob);
            $this->fail('A pending Docker label reconciliation must prevent resume.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('job', $exception->errors());
        }

        $stalePausedJob = $job->fresh();
        $job->forceFill([
            'status' => BackupJob::STATUS_ACTIVE,
            'pending_label_reconciliation' => null,
        ])->save();

        try {
            app(ResumeBackupJob::class)->handle($stalePausedJob);
            $this->fail('An active locked job must prevent resume.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('job', $exception->errors());
        }

        $this->assertSame(BackupJob::STATUS_ACTIVE, $job->fresh()->status);
    }

    public function test_resuming_a_member_keeps_a_locked_paused_group_paused(): void
    {
        $group = $this->group([
            'status' => BackupJobGroup::STATUS_PAUSED,
            'next_run_at' => now()->subHour(),
        ]);
        $job = $this->job($this->destination(), [
            'backup_job_group_id' => $group->id,
            'status' => BackupJob::STATUS_PAUSED,
        ]);

        app(ResumeBackupJob::class)->handle($job);

        $this->assertSame(BackupJob::STATUS_ACTIVE, $job->fresh()->status);
        $this->assertNull($job->fresh()->next_run_at);
        $this->assertSame(BackupJobGroup::STATUS_PAUSED, $group->fresh()->status);
        $this->assertTrue($group->fresh()->next_run_at->isPast());
    }

    public function test_group_resume_uses_locked_status_and_schedule(): void
    {
        Carbon::setTestNow('2026-09-03 12:00:00 UTC');
        $group = $this->group(['status' => BackupJobGroup::STATUS_PAUSED]);
        $group->newQuery()->whereKey($group->id)->update([
            'schedule_config' => json_encode(['time' => '08:15']),
            'cron_expression' => '15 8 * * *',
        ]);

        app(ResumeBackupJobGroup::class)->handle($group);

        $this->assertTrue($group->fresh()->next_run_at->equalTo(Carbon::parse('2026-09-04 08:15:00 UTC')));

        $staleGroup = $group->fresh();
        $group->newQuery()->whereKey($group->id)->update(['status' => BackupJobGroup::STATUS_ACTIVE]);

        $this->expectException(ValidationException::class);
        app(ResumeBackupJobGroup::class)->handle($staleGroup);
    }

    public function test_web_and_api_member_resume_have_the_same_group_behavior(): void
    {
        Carbon::setTestNow('2026-09-03 12:00:00 UTC');
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('resume-parity', ['read', 'write'])->plainTextToken;
        $webGroup = $this->group(['name' => 'Web', 'status' => BackupJobGroup::STATUS_ERROR]);
        $webMember = $this->job($this->destination('Web'), [
            'backup_job_group_id' => $webGroup->id,
            'status' => BackupJob::STATUS_ERROR,
        ]);
        $apiGroup = $this->group(['name' => 'API', 'status' => BackupJobGroup::STATUS_ERROR]);
        $apiMember = $this->job($this->destination('API'), [
            'backup_job_group_id' => $apiGroup->id,
            'status' => BackupJob::STATUS_ERROR,
        ]);

        $this->actingAs($admin)
            ->post(route('backup-jobs.resume', $webMember))
            ->assertSessionHas('success');
        $this->withToken($token)
            ->postJson("/api/v1/backup-jobs/{$apiMember->id}/resume")
            ->assertOk();

        $this->assertSame($webMember->fresh()->status, $apiMember->fresh()->status);
        $this->assertNull($webMember->fresh()->next_run_at);
        $this->assertNull($apiMember->fresh()->next_run_at);
        $this->assertSame($webGroup->fresh()->status, $apiGroup->fresh()->status);
        $this->assertTrue($webGroup->fresh()->next_run_at->equalTo($apiGroup->fresh()->next_run_at));
    }

    public function test_api_resume_rejects_running_jobs_and_groups_without_changing_them(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('resume-running', ['read', 'write'])->plainTextToken;
        $group = $this->group(['status' => BackupJobGroup::STATUS_RUNNING]);
        $job = $this->job($this->destination(), [
            'backup_job_group_id' => $group->id,
            'status' => BackupJob::STATUS_RUNNING,
        ]);

        $this->withToken($token)
            ->postJson("/api/v1/backup-jobs/{$job->id}/resume")
            ->assertStatus(422)
            ->assertJsonValidationErrors('job');
        $this->withToken($token)
            ->postJson("/api/v1/backup-groups/{$group->id}/resume")
            ->assertStatus(422)
            ->assertJsonValidationErrors('group');

        $this->assertSame(BackupJob::STATUS_RUNNING, $job->fresh()->status);
        $this->assertSame(BackupJobGroup::STATUS_RUNNING, $group->fresh()->status);
    }

    public function test_web_resume_rejects_active_jobs_and_groups_with_flash_errors_without_changes(): void
    {
        $admin = User::factory()->admin()->create();
        $group = $this->group([
            'pause_reason' => 'preserve group reason',
            'next_run_at' => now()->subDay(),
        ]);
        $job = $this->job($this->destination(), [
            'pause_reason' => 'preserve job reason',
            'last_error' => 'preserve job error',
            'next_run_at' => now()->subDay(),
        ]);
        $groupBefore = $group->only(['status', 'pause_reason', 'last_error', 'last_error_at', 'next_run_at']);
        $jobBefore = $job->only(['status', 'pause_reason', 'last_error', 'last_error_at', 'next_run_at']);

        $this->actingAs($admin)
            ->post(route('backup-jobs.resume', $job))
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->actingAs($admin)
            ->post(route('backup-groups.resume', $group))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertEquals($jobBefore, $job->fresh()->only(array_keys($jobBefore)));
        $this->assertEquals($groupBefore, $group->fresh()->only(array_keys($groupBefore)));
    }

    public function test_api_resume_rejects_active_jobs_and_groups_with_validation_errors_without_changes(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('resume-active', ['read', 'write'])->plainTextToken;
        $group = $this->group([
            'pause_reason' => 'preserve group reason',
            'next_run_at' => now()->subDay(),
        ]);
        $job = $this->job($this->destination(), [
            'pause_reason' => 'preserve job reason',
            'last_error' => 'preserve job error',
            'next_run_at' => now()->subDay(),
        ]);
        $groupBefore = $group->only(['status', 'pause_reason', 'last_error', 'last_error_at', 'next_run_at']);
        $jobBefore = $job->only(['status', 'pause_reason', 'last_error', 'last_error_at', 'next_run_at']);

        $this->withToken($token)
            ->postJson("/api/v1/backup-jobs/{$job->id}/resume")
            ->assertStatus(422)
            ->assertJsonValidationErrors('job');
        $this->withToken($token)
            ->postJson("/api/v1/backup-groups/{$group->id}/resume")
            ->assertStatus(422)
            ->assertJsonValidationErrors('group');

        $this->assertEquals($jobBefore, $job->fresh()->only(array_keys($jobBefore)));
        $this->assertEquals($groupBefore, $group->fresh()->only(array_keys($groupBefore)));
    }

    private function destination(string $name = 'Local'): BackupDestination
    {
        return BackupDestination::create([
            'name' => $name,
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'is_active' => true,
            'settings' => ['archive_path' => sys_get_temp_dir().'/vv'],
        ]);
    }

    private function group(array $attributes = []): BackupJobGroup
    {
        return BackupJobGroup::create(array_merge([
            'name' => 'Group',
            'schedule_type' => BackupJobGroup::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJobGroup::STATUS_ACTIVE,
            'failure_policy' => BackupJobGroup::FAILURE_POLICY_CONTINUE,
            'next_run_at' => now()->addDay(),
        ], $attributes));
    }

    private function job(BackupDestination $destination, array $attributes = []): BackupJob
    {
        return BackupJob::create(array_merge([
            'name' => 'Job',
            'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
            'volume_name' => 'volume',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ], $attributes));
    }
}
