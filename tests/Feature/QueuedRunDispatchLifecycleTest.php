<?php

namespace Tests\Feature;

use App\Actions\Backup\RunBackup;
use App\Actions\Backup\RunBackupGroup;
use App\Actions\Runs\DispatchQueuedRun;
use App\Jobs\RunBackupGroupJob;
use App\Jobs\RunBackupJob;
use App\Jobs\RunRestoreJob;
use App\Models\BackupDestination;
use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\RestoreRun;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class QueuedRunDispatchLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
    }

    public function test_dispatch_exceptions_clear_each_run_type_lease_and_leave_the_runs_recoverable(): void
    {
        $runs = [$this->backupRun(), $this->restoreRun(), $this->groupRun()];
        $this->mock(Dispatcher::class)
            ->shouldReceive('dispatch')
            ->times(count($runs))
            ->andThrow(new RuntimeException('queue unavailable'));

        foreach ($runs as $run) {
            try {
                app(DispatchQueuedRun::class)->handle($run);
                $this->fail('The dispatch exception should be propagated.');
            } catch (RuntimeException $exception) {
                $this->assertSame('queue unavailable', $exception->getMessage());
            }

            $run->refresh();
            $this->assertSame(BackupRun::STATUS_QUEUED, $run->status);
            $this->assertNull($run->dispatch_token);
            $this->assertNull($run->dispatch_attempted_at);
            $this->assertNull($run->dispatch_published_at);
        }
    }

    public function test_sync_queue_is_rejected_before_any_run_is_claimed(): void
    {
        config(['queue.default' => 'sync']);

        foreach ([$this->backupRun(), $this->restoreRun(), $this->groupRun()] as $run) {
            try {
                app(DispatchQueuedRun::class)->handle($run);
                $this->fail('The synchronous queue should be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('QUEUE_CONNECTION=sync is not supported', $exception->getMessage());
            }

            $run->refresh();
            $this->assertSame(BackupRun::STATUS_QUEUED, $run->status);
            $this->assertNull($run->dispatch_token);
            $this->assertNull($run->dispatch_attempted_at);
            $this->assertNull($run->dispatch_published_at);
        }
    }

    public function test_sweep_redispatches_backup_restore_and_group_runs_with_missing_or_stale_leases(): void
    {
        Queue::fake();
        $backupRun = $this->backupRun();
        $restoreRun = $this->restoreRun();
        $groupRun = $this->groupRun();
        $restoreRun->forceFill(['dispatch_attempted_at' => now()->subMinutes(DispatchQueuedRun::LEASE_MINUTES + 1)])->save();
        $groupRun->forceFill(['dispatch_attempted_at' => now()->subMinutes(DispatchQueuedRun::LEASE_MINUTES + 1)])->save();

        $this->artisan('volumevault:dispatch-queued-runs')->assertSuccessful();

        $backupRun->refresh();
        $restoreRun->refresh();
        $groupRun->refresh();
        Queue::assertPushed(RunBackupJob::class, fn (RunBackupJob $job): bool => $job->backupRunId === $backupRun->id && $job->dispatchToken === $backupRun->dispatch_token);
        Queue::assertPushed(RunRestoreJob::class, fn (RunRestoreJob $job): bool => $job->restoreRunId === $restoreRun->id && $job->dispatchToken === $restoreRun->dispatch_token);
        Queue::assertPushed(RunBackupGroupJob::class, fn (RunBackupGroupJob $job): bool => $job->backupGroupRunId === $groupRun->id && $job->dispatchToken === $groupRun->dispatch_token);
        $this->assertNotNull($backupRun->dispatch_token);
        $this->assertNotNull($restoreRun->dispatch_token);
        $this->assertNotNull($groupRun->dispatch_token);
        $this->assertNotNull($backupRun->dispatch_attempted_at);
        $this->assertNotNull($restoreRun->dispatch_attempted_at);
        $this->assertNotNull($groupRun->dispatch_attempted_at);
        $this->assertNotNull($backupRun->dispatch_published_at);
        $this->assertNotNull($restoreRun->dispatch_published_at);
        $this->assertNotNull($groupRun->dispatch_published_at);
    }

    public function test_sweep_does_not_republish_a_fresh_confirmed_payload(): void
    {
        Queue::fake();
        $run = $this->backupRun();
        $run->forceFill([
            'dispatch_attempted_at' => now(),
            'dispatch_published_at' => now(),
        ])->save();

        $this->artisan('volumevault:dispatch-queued-runs')->assertSuccessful();

        Queue::assertNotPushed(RunBackupJob::class, fn (RunBackupJob $job): bool => $job->backupRunId === $run->id);
        $this->assertSame($run->dispatch_published_at->timestamp, $run->fresh()->dispatch_published_at->timestamp);
    }

    public function test_sweep_republishes_stale_confirmed_payloads_without_invalidating_the_existing_generation(): void
    {
        Queue::fake();
        $runs = [$this->backupRun(), $this->restoreRun(), $this->groupRun()];
        $publishedAt = now()->subMinutes(DispatchQueuedRun::LEASE_MINUTES + 1);

        foreach ($runs as $run) {
            $run->forceFill([
                'dispatch_token' => 'lost-'.$run::class,
                'dispatch_attempted_at' => $publishedAt,
                'dispatch_published_at' => $publishedAt,
            ])->save();
        }

        $this->artisan('volumevault:dispatch-queued-runs')->assertSuccessful();
        $this->travel(DispatchQueuedRun::LEASE_MINUTES + 1)->minutes();
        $this->artisan('volumevault:dispatch-queued-runs')->assertSuccessful();

        foreach ($runs as $run) {
            $run->refresh();
            $this->assertSame('lost-'.$run::class, $run->dispatch_token);
            $this->assertSame($publishedAt->timestamp, $run->dispatch_published_at->timestamp);
            $this->assertTrue($run->dispatch_attempted_at->isAfter($run->dispatch_published_at));
        }

        Queue::assertPushed(RunBackupJob::class, 1);
        Queue::assertPushed(RunRestoreJob::class, 1);
        Queue::assertPushed(RunBackupGroupJob::class, 1);
    }

    public function test_failed_stale_republication_restores_the_previous_attempt_lease(): void
    {
        $run = $this->backupRun();
        $attemptedAt = now()->subMinutes(DispatchQueuedRun::LEASE_MINUTES + 2);
        $publishedAt = now()->subMinutes(DispatchQueuedRun::LEASE_MINUTES + 1);
        $run->forceFill([
            'dispatch_token' => 'existing-generation',
            'dispatch_attempted_at' => $attemptedAt,
            'dispatch_published_at' => $publishedAt,
        ])->save();
        $this->mock(Dispatcher::class)
            ->shouldReceive('dispatch')
            ->once()
            ->andThrow(new RuntimeException('queue unavailable'));

        try {
            app(DispatchQueuedRun::class)->handle($run);
            $this->fail('The dispatch exception should be propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('queue unavailable', $exception->getMessage());
        }

        $run->refresh();
        $this->assertSame('existing-generation', $run->dispatch_token);
        $this->assertSame($attemptedAt->timestamp, $run->dispatch_attempted_at->timestamp);
        $this->assertSame($publishedAt->timestamp, $run->dispatch_published_at->timestamp);
    }

    public function test_stale_failed_payloads_cannot_clear_a_newer_dispatch_generation(): void
    {
        foreach ([$this->backupRun(), $this->restoreRun(), $this->groupRun()] as $run) {
            $newToken = 'new-'.$run::class;
            $attemptedAt = now()->subMinute();
            $publishedAt = now();
            $run->forceFill([
                'dispatch_token' => $newToken,
                'dispatch_attempted_at' => $attemptedAt,
                'dispatch_published_at' => $publishedAt,
            ])->save();

            $this->queueJob($run, 'old-'.$run::class)->failed(new RuntimeException('stale payload'));

            $run->refresh();
            $this->assertSame($newToken, $run->dispatch_token);
            $this->assertSame($attemptedAt->timestamp, $run->dispatch_attempted_at->timestamp);
            $this->assertSame($publishedAt->timestamp, $run->dispatch_published_at->timestamp);
        }
    }

    public function test_current_failed_payloads_clear_their_dispatch_generation(): void
    {
        foreach ([$this->backupRun(), $this->restoreRun(), $this->groupRun()] as $run) {
            $token = 'current-'.$run::class;
            $run->forceFill([
                'dispatch_token' => $token,
                'dispatch_attempted_at' => now(),
                'dispatch_published_at' => now(),
            ])->save();

            $this->queueJob($run, $token)->failed(new RuntimeException('current payload'));

            $run->refresh();
            $this->assertSame(BackupRun::STATUS_QUEUED, $run->status);
            $this->assertNull($run->dispatch_token);
            $this->assertNull($run->dispatch_attempted_at);
            $this->assertNull($run->dispatch_published_at);
        }
    }

    public function test_late_dispatch_completion_cannot_confirm_a_newer_generation(): void
    {
        $run = $this->backupRun();
        $this->mock(Dispatcher::class)
            ->shouldReceive('dispatch')
            ->once()
            ->andReturnUsing(function () use ($run): int {
                BackupRun::query()->whereKey($run->id)->update([
                    'dispatch_token' => 'newer-generation',
                    'dispatch_attempted_at' => now(),
                    'dispatch_published_at' => null,
                ]);

                return 1;
            });

        $this->assertTrue(app(DispatchQueuedRun::class)->handle($run));

        $this->assertSame('newer-generation', $run->dispatch_token);
        $this->assertNull($run->dispatch_published_at);
        $this->assertSame('newer-generation', $run->fresh()->dispatch_token);
    }

    public function test_late_dispatch_completion_cannot_confirm_a_run_already_claimed_by_the_worker(): void
    {
        $run = $this->backupRun();
        $this->mock(Dispatcher::class)
            ->shouldReceive('dispatch')
            ->once()
            ->andReturnUsing(function () use ($run): int {
                BackupRun::query()->whereKey($run->id)->update([
                    'status' => BackupRun::STATUS_RUNNING,
                    'started_at' => now(),
                    'last_heartbeat_at' => now(),
                ]);

                return 1;
            });

        $this->assertTrue(app(DispatchQueuedRun::class)->handle($run));

        $run->refresh();
        $this->assertSame(BackupRun::STATUS_RUNNING, $run->status);
        $this->assertNull($run->dispatch_published_at);
    }

    public function test_sweep_excludes_inline_backup_runs(): void
    {
        Queue::fake();
        $groupRun = $this->groupRun();
        $groupMember = $this->backupRun([
            'backup_group_run_id' => $groupRun->id,
        ]);
        $preRestore = $this->backupRun([
            'trigger' => BackupRun::TRIGGER_PRE_RESTORE,
        ]);

        $this->artisan('volumevault:dispatch-queued-runs')->assertSuccessful();

        Queue::assertNotPushed(RunBackupJob::class, fn (RunBackupJob $job): bool => in_array($job->backupRunId, [$groupMember->id, $preRestore->id], true));
        $this->assertNull($groupMember->refresh()->dispatch_attempted_at);
        $this->assertNull($preRestore->refresh()->dispatch_attempted_at);
    }

    public function test_queued_candidate_claimed_before_mark_failed_is_not_failed(): void
    {
        $run = $this->backupRun();
        $cutoff = now()->subMinutes(15);
        $run->forceFill(['created_at' => now()->subHour()])->save();
        BackupRun::query()->whereKey($run->id)->update([
            'status' => BackupRun::STATUS_RUNNING,
            'started_at' => now(),
            'last_heartbeat_at' => now(),
        ]);

        $transitioned = app(RunBackup::class)->markFailed(
            $run,
            new RuntimeException('stale queued candidate'),
            BackupRun::STATUS_QUEUED,
            $cutoff,
        );

        $this->assertFalse($transitioned);
        $this->assertSame(BackupRun::STATUS_RUNNING, $run->refresh()->status);
    }

    public function test_group_heartbeat_renewed_after_selection_is_revalidated_under_locks(): void
    {
        $run = $this->groupRun([
            'status' => BackupGroupRun::STATUS_RUNNING,
            'started_at' => now()->subHour(),
            'last_heartbeat_at' => now()->subHour(),
        ]);
        $cutoff = now()->subMinutes(15);
        BackupGroupRun::query()->whereKey($run->id)->update(['last_heartbeat_at' => now()]);

        $transitioned = app(RunBackupGroup::class)->markFailed(
            $run,
            new RuntimeException('stale group candidate'),
            BackupGroupRun::STATUS_RUNNING,
            $cutoff,
        );

        $this->assertFalse($transitioned);
        $this->assertSame(BackupGroupRun::STATUS_RUNNING, $run->refresh()->status);
    }

    public function test_fresh_group_dispatch_attempt_is_revalidated_under_locks_before_stale_failure(): void
    {
        $run = $this->groupRun();
        $cutoff = now()->subMinutes(15);
        $run->forceFill(['created_at' => now()->subHour()])->save();
        BackupGroupRun::query()->whereKey($run->id)->update([
            'dispatch_token' => 'fresh-attempt',
            'dispatch_attempted_at' => now(),
        ]);

        $transitioned = app(RunBackupGroup::class)->markFailed(
            $run,
            new RuntimeException('stale group candidate'),
            BackupGroupRun::STATUS_QUEUED,
            $cutoff,
        );

        $this->assertFalse($transitioned);
        $this->assertSame(BackupGroupRun::STATUS_QUEUED, $run->refresh()->status);
    }

    /** @param array<string, mixed> $attributes */
    private function backupRun(array $attributes = []): BackupRun
    {
        return BackupRun::create([
            'backup_job_id' => $attributes['backup_job_id'] ?? $this->backupJob()->id,
            'status' => BackupRun::STATUS_QUEUED,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            ...$attributes,
        ]);
    }

    private function restoreRun(): RestoreRun
    {
        $job = $this->backupJob();

        return RestoreRun::create([
            'backup_job_id' => $job->id,
            'backup_destination_id' => $job->backup_destination_id,
            'selected_backup_key' => 'backup.tar.gz',
            'source_volume_name' => 'app_data',
            'target_volume_name' => 'app_data_restored',
            'mode' => RestoreRun::MODE_NEW_VOLUME,
            'status' => RestoreRun::STATUS_QUEUED,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function groupRun(array $attributes = []): BackupGroupRun
    {
        $group = BackupJobGroup::create([
            'name' => 'Nightly',
            'schedule_type' => BackupJobGroup::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJobGroup::STATUS_ACTIVE,
        ]);

        return BackupGroupRun::create([
            'backup_job_group_id' => $group->id,
            'status' => BackupGroupRun::STATUS_QUEUED,
            'trigger' => BackupGroupRun::TRIGGER_SCHEDULED,
            ...$attributes,
        ]);
    }

    private function backupJob(): BackupJob
    {
        $destination = BackupDestination::create([
            'name' => 'Local',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['archive_path' => '/tmp/vv', 'archive_mount_source' => '/tmp/vv'],
        ]);

        return BackupJob::create([
            'name' => 'Local app backup',
            'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);
    }

    private function queueJob(BackupRun|RestoreRun|BackupGroupRun $run, string $dispatchToken): RunBackupJob|RunRestoreJob|RunBackupGroupJob
    {
        return match (true) {
            $run instanceof BackupRun => new RunBackupJob($run->id, $dispatchToken),
            $run instanceof RestoreRun => new RunRestoreJob($run->id, $dispatchToken),
            $run instanceof BackupGroupRun => new RunBackupGroupJob($run->id, $dispatchToken),
        };
    }
}
