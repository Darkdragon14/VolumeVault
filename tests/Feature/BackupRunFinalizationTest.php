<?php

namespace Tests\Feature;

use App\Actions\Backup\RunBackup;
use App\Actions\Notifications\DeleteNotificationChannel;
use App\Actions\Notifications\MutateNotificationChannel;
use App\Actions\Notifications\NotificationChannelMutationBlocked;
use App\Actions\Runs\CreateRunFinalizations as CreateBackupRunFinalizations;
use App\Actions\Runs\ProcessRunFinalization as ProcessBackupRunFinalization;
use App\Jobs\ProcessRunFinalizationJob as ProcessBackupRunFinalizationJob;
use App\Jobs\RecordArchiveMetadataJob;
use App\Models\BackupDestination;
use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\NotificationChannel;
use App\Models\RunFinalization as BackupRunFinalization;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use App\Services\Notifications\SendShoutrrrNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class BackupRunFinalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_failed_and_stale_claims_at_max_attempts_terminalize_without_execution(): void
    {
        foreach ([BackupRunFinalization::STATUS_PENDING, BackupRunFinalization::STATUS_FAILED, BackupRunFinalization::STATUS_PROCESSING] as $status) {
            $run = $this->backupRun();
            $attributes = ['status' => $status, 'attempts' => BackupRunFinalization::MAX_ATTEMPTS];

            if ($status === BackupRunFinalization::STATUS_PROCESSING) {
                $attributes += ['claimed_at' => now()->subMinutes(11), 'claim_token' => (string) Str::uuid()];
            }

            $finalization = $this->finalization(BackupRunFinalization::TYPE_ARCHIVE_METADATA, $attributes, $run);
            $runBackup = Mockery::mock(RunBackup::class);
            $runBackup->shouldNotReceive('detectArchiveMetadata');

            $this->processor($runBackup)->handle($finalization->id);

            $this->assertSame(BackupRunFinalization::STATUS_FAILED, $finalization->refresh()->status);
            $this->assertSame(BackupRunFinalization::MAX_ATTEMPTS, $finalization->attempts);
            $this->assertNull($finalization->available_at);
            $this->assertNotNull($finalization->finished_at);
            $this->assertFalse($run->refresh()->archive_metadata_pending);
        }
    }

    public function test_failed_callback_only_transitions_its_own_claim_or_enqueue_lease(): void
    {
        $claimToken = (string) Str::uuid();
        $enqueueToken = (string) Str::uuid();
        $finalization = $this->finalization(BackupRunFinalization::TYPE_ARCHIVE_METADATA, [
            'status' => BackupRunFinalization::STATUS_PROCESSING,
            'attempts' => 1,
            'claimed_at' => now(),
            'claim_token' => $claimToken,
            'enqueue_token' => $enqueueToken,
            'enqueued_at' => now(),
        ]);

        (new ProcessBackupRunFinalizationJob($finalization->id, $enqueueToken, $claimToken))->failed(new RuntimeException('worker timeout'));

        $this->assertSame(BackupRunFinalization::STATUS_FAILED, $finalization->refresh()->status);
        $this->assertSame('worker timeout', $finalization->last_error);
        $this->assertNull($finalization->enqueue_token);
        $this->assertNull($finalization->enqueued_at);

        $newLease = (string) Str::uuid();
        $finalization->forceFill([
            'status' => BackupRunFinalization::STATUS_PENDING,
            'enqueue_token' => $newLease,
            'enqueued_at' => now(),
        ])->save();

        app(ProcessBackupRunFinalization::class)->failExecution($finalization->id, $enqueueToken, $claimToken, new RuntimeException('stale callback'));

        $this->assertSame($newLease, $finalization->refresh()->enqueue_token);
    }

    public function test_failed_callback_releases_its_own_enqueue_lease_before_claiming(): void
    {
        $enqueueToken = (string) Str::uuid();
        $finalization = $this->finalization(BackupRunFinalization::TYPE_ARCHIVE_METADATA, [
            'enqueue_token' => $enqueueToken,
            'enqueued_at' => now(),
        ]);

        app(ProcessBackupRunFinalization::class)->failExecution(
            $finalization->id,
            $enqueueToken,
            (string) Str::uuid(),
            new RuntimeException('worker failed before claim'),
        );

        $this->assertSame(BackupRunFinalization::STATUS_FAILED, $finalization->refresh()->status);
        $this->assertSame(1, $finalization->attempts);
        $this->assertNull($finalization->enqueue_token);
        $this->assertSame('worker failed before claim', $finalization->last_error);
    }

    public function test_failed_callback_terminalizes_an_exhausted_enqueue_lease(): void
    {
        $enqueueToken = (string) Str::uuid();
        $finalization = $this->finalization(BackupRunFinalization::TYPE_ARCHIVE_METADATA, [
            'attempts' => BackupRunFinalization::MAX_ATTEMPTS - 1,
            'enqueue_token' => $enqueueToken,
            'enqueued_at' => now(),
        ]);

        app(ProcessBackupRunFinalization::class)->failExecution(
            $finalization->id,
            $enqueueToken,
            (string) Str::uuid(),
            new RuntimeException('worker timed out before claim'),
        );

        $finalization->refresh();
        $this->assertSame(BackupRunFinalization::STATUS_FAILED, $finalization->status);
        $this->assertSame(BackupRunFinalization::MAX_ATTEMPTS, $finalization->attempts);
        $this->assertNull($finalization->available_at);
        $this->assertNull($finalization->enqueue_token);
        $this->assertNotNull($finalization->finished_at);
        $this->assertFalse($finalization->backupRun->refresh()->archive_metadata_pending);
    }

    public function test_failed_callback_does_not_increment_an_already_exhausted_enqueue_lease(): void
    {
        $enqueueToken = (string) Str::uuid();
        $finalization = $this->finalization(BackupRunFinalization::TYPE_ARCHIVE_METADATA, [
            'attempts' => BackupRunFinalization::MAX_ATTEMPTS,
            'enqueue_token' => $enqueueToken,
            'enqueued_at' => now(),
        ]);

        app(ProcessBackupRunFinalization::class)->failExecution(
            $finalization->id,
            $enqueueToken,
            (string) Str::uuid(),
            new RuntimeException('worker timed out before claim'),
        );

        $finalization->refresh();
        $this->assertSame(BackupRunFinalization::MAX_ATTEMPTS, $finalization->attempts);
        $this->assertSame(BackupRunFinalization::STATUS_FAILED, $finalization->status);
        $this->assertNull($finalization->available_at);
        $this->assertNull($finalization->enqueue_token);
        $this->assertNotNull($finalization->finished_at);
    }

    public function test_sequential_pre_claim_failures_use_attempt_based_backoff(): void
    {
        $this->freezeTime();
        $finalization = $this->finalization(BackupRunFinalization::TYPE_ARCHIVE_METADATA);

        foreach ([1, 5, 15, 60] as $index => $delayInMinutes) {
            $enqueueToken = (string) Str::uuid();
            $finalization->forceFill([
                'enqueue_token' => $enqueueToken,
                'enqueued_at' => now(),
            ])->save();

            app(ProcessBackupRunFinalization::class)->failExecution(
                $finalization->id,
                $enqueueToken,
                (string) Str::uuid(),
                new RuntimeException('worker failed before claim'),
            );

            $finalization->refresh();
            $this->assertSame($index + 1, $finalization->attempts);
            $this->assertSame(now()->addMinutes($delayInMinutes)->toDateTimeString(), $finalization->available_at->toDateTimeString());
        }
    }

    public function test_failed_callback_cannot_regress_terminal_failure_with_matching_enqueue_token(): void
    {
        $enqueueToken = (string) Str::uuid();
        $finishedAt = now()->subMinute();
        $finalization = $this->finalization(BackupRunFinalization::TYPE_ARCHIVE_METADATA, [
            'status' => BackupRunFinalization::STATUS_FAILED,
            'attempts' => BackupRunFinalization::MAX_ATTEMPTS,
            'available_at' => null,
            'finished_at' => $finishedAt,
            'enqueue_token' => $enqueueToken,
            'enqueued_at' => now(),
            'last_error' => 'terminal failure',
        ]);
        $finishedAt = $finalization->fresh()->finished_at;

        app(ProcessBackupRunFinalization::class)->failExecution(
            $finalization->id,
            $enqueueToken,
            (string) Str::uuid(),
            new RuntimeException('late callback'),
        );

        $finalization->refresh();
        $this->assertSame(BackupRunFinalization::STATUS_FAILED, $finalization->status);
        $this->assertSame(BackupRunFinalization::MAX_ATTEMPTS, $finalization->attempts);
        $this->assertSame('terminal failure', $finalization->last_error);
        $this->assertTrue($finishedAt->equalTo($finalization->finished_at));
        $this->assertNull($finalization->enqueue_token);
        $this->assertNull($finalization->enqueued_at);
    }

    public function test_stale_claimant_cannot_clear_metadata_mirror_after_a_new_claim_fails(): void
    {
        Exceptions::fake();
        $finalization = $this->finalization(BackupRunFinalization::TYPE_ARCHIVE_METADATA);
        $runBackup = Mockery::mock(RunBackup::class);
        $processor = $this->processor($runBackup);
        $invocation = 0;
        $runBackup->shouldReceive('detectArchiveMetadata')->twice()->andReturnUsing(function () use (&$invocation, $processor, $finalization): array {
            $invocation++;

            if ($invocation === 1) {
                $this->travel(11)->minutes();
                $processor->handle($finalization->id);

                return ['backup_key' => 'stale.tar.gz', 'backup_size_bytes' => 10];
            }

            throw new RuntimeException('new claimant failed');
        });

        $processor->handle($finalization->id);

        $this->assertSame(BackupRunFinalization::STATUS_FAILED, $finalization->refresh()->status);
        $this->assertTrue($finalization->backupRun->refresh()->archive_metadata_pending);
        $this->assertNull($finalization->backupRun->backup_key);
    }

    public function test_recipients_are_frozen_across_job_and_channel_configuration_changes(): void
    {
        $run = $this->backupRun(BackupRun::STATUS_FAILED);
        $original = $this->channel('Original');
        $replacement = $this->channel('Replacement');
        $run->job->notificationChannels()->attach($original);

        $ids = app(CreateBackupRunFinalizations::class)->createBackupNotifications($run, $run->job);
        $run->job->notificationChannels()->sync([$replacement->id]);
        $run->job->forceFill(['notifications_enabled' => false])->save();
        $replacement->forceFill(['is_active' => false])->save();

        $notifier = Mockery::mock(SendShoutrrrNotification::class);
        $notifier->shouldReceive('sendBackupRunFinishedToChannel')->once()->withArgs(
            fn (BackupRun $deliveredRun, NotificationChannel $channel): bool => $deliveredRun->is($run) && $channel->is($original),
        );

        $this->processor(notifier: $notifier)->handle($ids[0]);

        $this->assertSame(BackupRunFinalization::STATUS_COMPLETED, BackupRunFinalization::findOrFail($ids[0])->status);
    }

    public function test_channel_update_deactivation_and_deletion_are_blocked_until_finalization_is_terminal(): void
    {
        $channel = $this->channel();
        $finalization = $this->notificationFinalization($this->backupRun(BackupRun::STATUS_FAILED), $channel);

        foreach ([['name' => 'Changed'], ['is_active' => false]] as $attributes) {
            try {
                app(MutateNotificationChannel::class)->update($channel, $attributes);
                $this->fail('Expected channel mutation to be blocked.');
            } catch (NotificationChannelMutationBlocked) {
                $this->assertTrue(true);
            }
        }

        try {
            app(DeleteNotificationChannel::class)->handle($channel);
            $this->fail('Expected channel deletion to be blocked.');
        } catch (NotificationChannelMutationBlocked) {
            $this->assertTrue(true);
        }

        $finalization->forceFill(['status' => BackupRunFinalization::STATUS_COMPLETED, 'finished_at' => now(), 'available_at' => null])->save();
        app(DeleteNotificationChannel::class)->handle($channel);
        $this->assertModelMissing($channel);
    }

    public function test_channel_mutation_and_deletion_are_allowed_after_terminal_failure(): void
    {
        $channel = $this->channel();
        $finalization = $this->notificationFinalization($this->backupRun(BackupRun::STATUS_FAILED), $channel);
        $finalization->forceFill(['status' => BackupRunFinalization::STATUS_FAILED, 'finished_at' => now(), 'available_at' => null])->save();

        $channel = app(MutateNotificationChannel::class)->update($channel, ['name' => 'Changed', 'is_active' => false]);
        app(DeleteNotificationChannel::class)->handle($channel);

        $this->assertModelMissing($channel);
        $this->assertNull($finalization->refresh()->notification_channel_id);
    }

    public function test_two_channels_retry_independently(): void
    {
        Exceptions::fake();
        $run = $this->backupRun(BackupRun::STATUS_FAILED);
        $first = $this->notificationFinalization($run, $this->channel('Failing'));
        $second = $this->notificationFinalization($run, $this->channel('Working'));
        $notifier = Mockery::mock(SendShoutrrrNotification::class);
        $notifier->shouldReceive('sendBackupRunFinishedToChannel')->once()->withArgs(fn ($run, NotificationChannel $channel) => $channel->name === 'Failing')->andThrow(new RuntimeException('offline'));
        $notifier->shouldReceive('sendBackupRunFinishedToChannel')->once()->withArgs(fn ($run, NotificationChannel $channel) => $channel->name === 'Working');
        $processor = $this->processor(notifier: $notifier);

        $processor->handle($first->id);
        $processor->handle($second->id);

        $this->assertSame(BackupRunFinalization::STATUS_FAILED, $first->refresh()->status);
        $this->assertSame(1, $first->attempts);
        $this->assertSame(BackupRunFinalization::STATUS_COMPLETED, $second->refresh()->status);
        $this->assertSame(1, $second->attempts);
    }

    public function test_notification_secret_from_stderr_is_not_reported_or_persisted(): void
    {
        Exceptions::fake();
        $secret = 'discord://super-secret-token@example';
        $channel = $this->channel();
        $channel->update(['url' => $secret]);
        $finalization = $this->notificationFinalization($this->backupRun(BackupRun::STATUS_FAILED), $channel);
        $dockerProcess = Mockery::mock(DockerProcess::class);
        $dockerProcess->shouldReceive('run')
            ->once()
            ->withArgs(fn (array $command, int $timeout, array $environment): bool => $timeout === 60 && $environment['SHOUTRRR_URL'] === $secret)
            ->andReturn(new DockerProcessResult([], 1, '', 'delivery failed for '.$secret));
        $this->app->instance(DockerProcess::class, $dockerProcess);

        app(ProcessBackupRunFinalization::class)->handle($finalization->id);

        $this->assertSame('Backup notification delivery failed.', $finalization->refresh()->last_error);
        $this->assertStringNotContainsString($secret, (string) $finalization->last_error);
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Backup notification delivery failed.');
    }

    public function test_success_notification_waits_for_metadata_completion_or_terminal_exhaustion(): void
    {
        Exceptions::fake();
        $run = $this->backupRun();
        $metadata = $this->finalization(BackupRunFinalization::TYPE_ARCHIVE_METADATA, ['attempts' => BackupRunFinalization::MAX_ATTEMPTS - 1], $run);
        $notification = $this->notificationFinalization($run, $this->channel());
        $runBackup = Mockery::mock(RunBackup::class);
        $runBackup->shouldReceive('detectArchiveMetadata')->once()->andThrow(new RuntimeException('listing failed'));
        $notifier = Mockery::mock(SendShoutrrrNotification::class);
        $notifier->shouldReceive('sendBackupRunFinishedToChannel')->once();
        $processor = $this->processor($runBackup, $notifier);

        $processor->handle($notification->id);
        $this->assertSame(0, $notification->refresh()->attempts);

        $processor->handle($metadata->id);
        $processor->handle($notification->id);

        $this->assertNotNull($metadata->refresh()->finished_at);
        $this->assertSame(BackupRunFinalization::STATUS_COMPLETED, $notification->refresh()->status);
    }

    public function test_failed_run_notification_has_no_metadata_dependency(): void
    {
        $run = $this->backupRun(BackupRun::STATUS_FAILED);
        $this->finalization(BackupRunFinalization::TYPE_ARCHIVE_METADATA, [], $run);
        $notification = $this->notificationFinalization($run, $this->channel());
        $notifier = Mockery::mock(SendShoutrrrNotification::class);
        $notifier->shouldReceive('sendBackupRunFinishedToChannel')->once();

        $this->processor(notifier: $notifier)->handle($notification->id);

        $this->assertSame(BackupRunFinalization::STATUS_COMPLETED, $notification->refresh()->status);
    }

    public function test_sweep_leases_make_more_than_one_hundred_rows_fair(): void
    {
        Queue::fake();

        for ($index = 0; $index < 101; $index++) {
            $this->finalization(BackupRunFinalization::TYPE_ARCHIVE_METADATA);
        }

        $processor = app(ProcessBackupRunFinalization::class);
        $this->assertSame(100, $processor->dispatchDue());
        $this->assertSame(1, $processor->dispatchDue());
        $this->assertSame(0, $processor->dispatchDue());
        Queue::assertPushed(ProcessBackupRunFinalizationJob::class, 101);
    }

    public function test_stale_enqueue_lease_is_recovered_with_new_tokens(): void
    {
        Queue::fake();
        $finalization = $this->finalization(BackupRunFinalization::TYPE_ARCHIVE_METADATA, [
            'enqueue_token' => (string) Str::uuid(),
            'enqueued_at' => now()->subMinutes(11),
        ]);
        $oldToken = $finalization->enqueue_token;

        $this->assertSame(1, app(ProcessBackupRunFinalization::class)->dispatchDue());

        $this->assertNotSame($oldToken, $finalization->refresh()->enqueue_token);
        Queue::assertPushed(ProcessBackupRunFinalizationJob::class, function (ProcessBackupRunFinalizationJob $job) use ($finalization): bool {
            return $job->runFinalizationId === $finalization->id
                && $job->enqueueToken === $finalization->enqueue_token
                && $job->claimToken !== $job->enqueueToken;
        });
    }

    public function test_original_admitted_job_claims_after_lease_rotation_and_recovery_cannot_steal_active_claim(): void
    {
        Queue::fake();
        $originalEnqueueToken = (string) Str::uuid();
        $originalClaimToken = (string) Str::uuid();
        $finalization = $this->finalization(BackupRunFinalization::TYPE_ARCHIVE_METADATA, [
            'enqueue_token' => $originalEnqueueToken,
            'enqueued_at' => now()->subMinutes(11),
        ]);
        $originalJob = new ProcessBackupRunFinalizationJob($finalization->id, $originalEnqueueToken, $originalClaimToken);

        $this->assertSame(1, app(ProcessBackupRunFinalization::class)->dispatchDue());

        $recoveryJob = null;
        Queue::assertPushed(ProcessBackupRunFinalizationJob::class, function (ProcessBackupRunFinalizationJob $job) use (&$recoveryJob): bool {
            $recoveryJob = $job;

            return true;
        });
        $this->assertNotSame($originalEnqueueToken, $finalization->refresh()->enqueue_token);

        $runBackup = Mockery::mock(RunBackup::class);
        $processor = $this->processor($runBackup);
        $runBackup->shouldReceive('detectArchiveMetadata')->once()->andReturnUsing(function () use ($finalization, $originalClaimToken, $recoveryJob, $processor): array {
            $this->assertSame(BackupRunFinalization::STATUS_PROCESSING, $finalization->refresh()->status);
            $this->assertSame($originalClaimToken, $finalization->claim_token);

            $recoveryJob->handle($processor);

            $this->assertSame(BackupRunFinalization::STATUS_PROCESSING, $finalization->refresh()->status);
            $this->assertSame($originalClaimToken, $finalization->claim_token);
            $this->assertSame(1, $finalization->attempts);

            return ['backup_key' => 'original.tar.gz', 'backup_size_bytes' => 42];
        });

        $originalJob->handle($processor);

        $this->assertSame(BackupRunFinalization::STATUS_COMPLETED, $finalization->refresh()->status);
        $this->assertSame(1, $finalization->attempts);
        $this->assertSame('original.tar.gz', $finalization->backupRun->refresh()->backup_key);
    }

    public function test_late_failed_callback_from_claim_a_cannot_mutate_active_claim_b(): void
    {
        $claimAToken = (string) Str::uuid();
        $claimBToken = (string) Str::uuid();
        $enqueueAToken = (string) Str::uuid();
        $finalization = $this->finalization(BackupRunFinalization::TYPE_ARCHIVE_METADATA, [
            'status' => BackupRunFinalization::STATUS_PROCESSING,
            'attempts' => 1,
            'claimed_at' => now()->subMinutes(11),
            'claim_token' => $claimAToken,
        ]);
        $runBackup = Mockery::mock(RunBackup::class);
        $runBackup->shouldReceive('detectArchiveMetadata')->once()->andReturnUsing(function () use ($finalization, $enqueueAToken, $claimAToken, $claimBToken): array {
            $finalization->refresh();
            $claimedAt = $finalization->claimed_at;
            $enqueueToken = $finalization->enqueue_token;
            $enqueuedAt = $finalization->enqueued_at;

            (new ProcessBackupRunFinalizationJob($finalization->id, $enqueueAToken, $claimAToken))
                ->failed(new RuntimeException('claim A failed late'));

            $finalization->refresh();
            $this->assertSame(BackupRunFinalization::STATUS_PROCESSING, $finalization->status);
            $this->assertSame($claimBToken, $finalization->claim_token);
            $this->assertSame(2, $finalization->attempts);
            $this->assertTrue($claimedAt->equalTo($finalization->claimed_at));
            $this->assertSame($enqueueToken, $finalization->enqueue_token);
            $this->assertEquals($enqueuedAt, $finalization->enqueued_at);

            return ['backup_key' => 'claim-b.tar.gz', 'backup_size_bytes' => 42];
        });

        $this->processor($runBackup)->handle($finalization->id, null, $claimBToken);

        $this->assertSame(BackupRunFinalization::STATUS_COMPLETED, $finalization->refresh()->status);
        $this->assertSame(2, $finalization->attempts);
    }

    public function test_dispatch_failure_clears_only_its_matching_enqueue_lease(): void
    {
        Exceptions::fake();
        $finalization = $this->finalization(BackupRunFinalization::TYPE_ARCHIVE_METADATA);
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue unavailable'));

        $this->assertSame(0, app(ProcessBackupRunFinalization::class)->dispatch([$finalization->id]));
        $this->assertNull($finalization->refresh()->enqueue_token);
        $this->assertSame('queue unavailable', $finalization->last_error);

        $newLease = (string) Str::uuid();
        $finalization->forceFill(['enqueue_token' => $newLease, 'enqueued_at' => now()])->save();
        app(ProcessBackupRunFinalization::class)->failExecution($finalization->id, (string) Str::uuid(), (string) Str::uuid(), new RuntimeException('old dispatch'));
        $this->assertSame($newLease, $finalization->refresh()->enqueue_token);
    }

    public function test_old_claim_completion_fences_a_recovery_failed_callback(): void
    {
        Queue::fake();
        $finalization = $this->finalization(BackupRunFinalization::TYPE_ARCHIVE_METADATA);
        $processor = $this->processor($runBackup = Mockery::mock(RunBackup::class));
        $recoveryJob = null;
        $runBackup->shouldReceive('detectArchiveMetadata')->once()->andReturnUsing(function () use ($processor, &$recoveryJob): array {
            $this->travel(11)->minutes();
            $this->assertSame(1, $processor->dispatchDue());
            Queue::assertPushed(ProcessBackupRunFinalizationJob::class, function (ProcessBackupRunFinalizationJob $job) use (&$recoveryJob): bool {
                $recoveryJob = $job;

                return true;
            });

            return ['backup_key' => 'old-claim.tar.gz', 'backup_size_bytes' => 42];
        });

        $processor->handle($finalization->id, null, 'old-claim');
        $completedAt = $finalization->refresh()->finished_at;
        $recoveryJob->failed(new RuntimeException('recovery worker exhausted'));

        $finalization->refresh();
        $this->assertSame(BackupRunFinalization::STATUS_COMPLETED, $finalization->status);
        $this->assertSame('old-claim.tar.gz', $finalization->backupRun->refresh()->backup_key);
        $this->assertTrue($completedAt->equalTo($finalization->finished_at));
        $this->assertNull($finalization->enqueue_token);
        $this->assertNull($finalization->enqueued_at);
    }

    public function test_recovery_failed_callback_preserves_old_claim_before_it_completes(): void
    {
        Queue::fake();
        $this->freezeTime();
        $finalization = $this->finalization(BackupRunFinalization::TYPE_ARCHIVE_METADATA);
        $processor = $this->processor($runBackup = Mockery::mock(RunBackup::class));
        $runBackup->shouldReceive('detectArchiveMetadata')->once()->andReturnUsing(function () use ($finalization, $processor): array {
            $this->travel(11)->minutes();
            $this->assertSame(1, $processor->dispatchDue());

            $recoveryJob = null;
            Queue::assertPushed(ProcessBackupRunFinalizationJob::class, function (ProcessBackupRunFinalizationJob $job) use (&$recoveryJob): bool {
                $recoveryJob = $job;

                return true;
            });

            $finalization->refresh();
            $status = $finalization->status;
            $attempts = $finalization->attempts;
            $claimToken = $finalization->claim_token;
            $claimedAt = $finalization->claimed_at;
            $finishedAt = $finalization->finished_at;

            $recoveryJob->failed(new RuntimeException('recovery worker exhausted'));

            $finalization->refresh();
            $this->assertSame($status, $finalization->status);
            $this->assertSame($attempts, $finalization->attempts);
            $this->assertSame($claimToken, $finalization->claim_token);
            $this->assertTrue($claimedAt->equalTo($finalization->claimed_at));
            $this->assertSame($finishedAt, $finalization->finished_at);
            $this->assertNull($finalization->enqueue_token);
            $this->assertNull($finalization->enqueued_at);
            $this->assertSame(now()->addSeconds(ProcessBackupRunFinalization::DISPATCH_BACKOFF_SECONDS)->toDateTimeString(), $finalization->available_at->toDateTimeString());
            $this->assertSame('recovery worker exhausted', $finalization->last_error);
            $this->assertTrue($finalization->backupRun->refresh()->archive_metadata_pending);

            return ['backup_key' => 'old-claim.tar.gz', 'backup_size_bytes' => 42];
        });

        $processor->handle($finalization->id, null, 'old-claim');

        $finalization->refresh();
        $this->assertSame(BackupRunFinalization::STATUS_COMPLETED, $finalization->status);
        $this->assertSame(1, $finalization->attempts);
        $this->assertSame('old-claim.tar.gz', $finalization->backupRun->refresh()->backup_key);
        $this->assertFalse($finalization->backupRun->archive_metadata_pending);
    }

    public function test_dispatch_failures_back_off_low_ids_without_consuming_execution_attempts(): void
    {
        Exceptions::fake();

        for ($index = 0; $index < 101; $index++) {
            $this->finalization(BackupRunFinalization::TYPE_ARCHIVE_METADATA);
        }

        Bus::shouldReceive('dispatch')->times(100)->andThrow(new RuntimeException('queue unavailable'))->ordered();
        Bus::shouldReceive('dispatch')->once()->andReturnNull()->ordered();
        $processor = app(ProcessBackupRunFinalization::class);

        $this->assertSame(0, $processor->dispatchDue());
        $this->assertSame(1, $processor->dispatchDue());
        $this->assertSame(0, BackupRunFinalization::query()->where('attempts', '>', 0)->count());
        $this->assertSame(100, BackupRunFinalization::query()->whereNotNull('available_at')->where('available_at', '>', now())->count());
    }

    public function test_stale_processing_dispatch_failure_respects_dispatch_backoff(): void
    {
        Exceptions::fake();
        $finalization = $this->finalization(BackupRunFinalization::TYPE_ARCHIVE_METADATA, [
            'status' => BackupRunFinalization::STATUS_PROCESSING,
            'attempts' => 1,
            'claimed_at' => now()->subMinutes(11),
            'claim_token' => (string) Str::uuid(),
        ]);
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue unavailable'));

        $processor = app(ProcessBackupRunFinalization::class);
        $this->assertSame(0, $processor->dispatchDue());
        $this->assertSame(0, $processor->dispatchDue());
        $this->assertSame(1, $finalization->refresh()->attempts);
    }

    public function test_legacy_metadata_creation_locks_job_then_run_and_processes_after_commit(): void
    {
        $run = $this->backupRun();
        $baselineTransactionLevel = DB::transactionLevel();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $processor = Mockery::mock(ProcessBackupRunFinalization::class);
        $processor->shouldReceive('handle')->once()->withArgs(function () use ($baselineTransactionLevel): bool {
            $this->assertSame($baselineTransactionLevel, DB::transactionLevel());

            return true;
        });

        (new RecordArchiveMetadataJob($run->id))->handle($processor, app(CreateBackupRunFinalizations::class));

        $jobQuery = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from "backup_jobs"'));
        $runQuery = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'select * from "backup_runs"'));
        $finalizationQuery = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'run_finalizations'));
        $this->assertIsInt($jobQuery);
        $this->assertIsInt($runQuery);
        $this->assertIsInt($finalizationQuery);
        $this->assertLessThan($runQuery, $jobQuery);
        $this->assertLessThan($finalizationQuery, $runQuery);
    }

    public function test_backfill_creates_metadata_only_for_pre_restore_nullified_group_and_ambiguous_rows(): void
    {
        $preRestore = $this->backupRun();
        $preRestore->forceFill(['trigger' => BackupRun::TRIGGER_PRE_RESTORE])->save();
        $nullifiedGroup = $this->backupRun();
        $ambiguous = $this->backupRun();

        Schema::drop('run_finalizations');
        $schemaMigration = require database_path('migrations/2026_08_28_010000_create_run_finalizations_table.php');
        $schemaMigration->up();
        $schemaMigration->up();
        $this->assertSame(0, BackupRunFinalization::count());

        $backfillMigration = require database_path('migrations/2026_09_10_134341_backfill_archive_metadata_run_finalizations.php');
        $backfillMigration->up();
        $backfillMigration->up();

        $this->assertSame(3, BackupRunFinalization::where('type', BackupRunFinalization::TYPE_ARCHIVE_METADATA)->count());
        $this->assertSame(0, BackupRunFinalization::where('type', BackupRunFinalization::TYPE_FINISHED_NOTIFICATION)->count());
        $this->assertEqualsCanonicalizing([$preRestore->id, $nullifiedGroup->id, $ambiguous->id], BackupRunFinalization::pluck('backup_run_id')->all());
    }

    public function test_legacy_record_metadata_boolean_creates_per_channel_rows_and_preserves_suppression(): void
    {
        $run = $this->backupRun();
        $channelA = $this->channel('A');
        $channelB = $this->channel('B');
        $run->job->notificationChannels()->attach([$channelA->id, $channelB->id]);
        $processor = Mockery::mock(ProcessBackupRunFinalization::class);
        $processor->shouldReceive('handle')->times(3);

        (new RecordArchiveMetadataJob($run->id, true))->handle($processor, app(CreateBackupRunFinalizations::class));

        $this->assertSame(1, $run->finalizations()->where('type', BackupRunFinalization::TYPE_ARCHIVE_METADATA)->count());
        $this->assertEqualsCanonicalizing([$channelA->id, $channelB->id], $run->finalizations()->where('type', BackupRunFinalization::TYPE_FINISHED_NOTIFICATION)->pluck('notification_channel_id')->all());

        foreach ([BackupRun::TRIGGER_PRE_RESTORE, BackupRun::TRIGGER_MANUAL] as $trigger) {
            $suppressed = $this->backupRun();
            $attributes = ['trigger' => $trigger];

            if ($trigger === BackupRun::TRIGGER_MANUAL) {
                $group = BackupJobGroup::create([
                    'name' => 'Group',
                    'schedule_type' => BackupJobGroup::SCHEDULE_DAILY,
                    'schedule_config' => ['time' => '02:00'],
                    'cron_expression' => '0 2 * * *',
                    'timezone' => 'UTC',
                    'status' => BackupJobGroup::STATUS_ACTIVE,
                ]);
                $groupRun = BackupGroupRun::create([
                    'backup_job_group_id' => $group->id,
                    'status' => BackupGroupRun::STATUS_SUCCESS,
                    'trigger' => BackupGroupRun::TRIGGER_MANUAL,
                ]);
                $attributes['backup_group_run_id'] = $groupRun->id;
            }

            $suppressed->forceFill($attributes)->save();
            $suppressed->job->notificationChannels()->attach($channelA);
            $processor->shouldReceive('handle')->once();
            (new RecordArchiveMetadataJob($suppressed->id, true))->handle($processor, app(CreateBackupRunFinalizations::class));
            $this->assertSame(0, $suppressed->finalizations()->where('type', BackupRunFinalization::TYPE_FINISHED_NOTIFICATION)->count());
        }
    }

    private function processor(?RunBackup $runBackup = null, ?SendShoutrrrNotification $notifier = null): ProcessBackupRunFinalization
    {
        return new ProcessBackupRunFinalization(
            $runBackup ?? Mockery::mock(RunBackup::class),
            $notifier ?? Mockery::mock(SendShoutrrrNotification::class),
        );
    }

    private function notificationFinalization(BackupRun $run, NotificationChannel $channel): BackupRunFinalization
    {
        return $this->finalization(BackupRunFinalization::TYPE_FINISHED_NOTIFICATION, ['notification_channel_id' => $channel->id], $run);
    }

    private function finalization(string $type, array $attributes = [], ?BackupRun $run = null): BackupRunFinalization
    {
        $run ??= $this->backupRun();
        $channelId = $attributes['notification_channel_id'] ?? null;

        return BackupRunFinalization::create(array_merge([
            'backup_run_id' => $run->id,
            'notification_channel_id' => $channelId,
            'type' => $type,
            'deduplication_key' => (string) Str::uuid(),
            'status' => BackupRunFinalization::STATUS_PENDING,
            'available_at' => now(),
        ], $attributes));
    }

    private function channel(string $name = 'Channel'): NotificationChannel
    {
        return NotificationChannel::create([
            'name' => $name,
            'service' => NotificationChannel::SERVICE_ADVANCED,
            'url' => 'ntfy://ntfy.sh/'.strtolower($name),
            'notification_level' => NotificationChannel::LEVEL_INFO,
            'is_active' => true,
        ]);
    }

    private function backupRun(string $status = BackupRun::STATUS_SUCCESS): BackupRun
    {
        $destination = BackupDestination::create([
            'name' => 'Local',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'is_active' => true,
            'settings' => ['archive_path' => sys_get_temp_dir(), 'archive_mount_source' => sys_get_temp_dir()],
        ]);
        $job = BackupJob::create([
            'name' => 'Backup',
            'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);

        return BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => $status,
            'trigger' => BackupRun::TRIGGER_MANUAL,
            'finished_at' => now(),
            'archive_metadata_pending' => true,
        ]);
    }
}
