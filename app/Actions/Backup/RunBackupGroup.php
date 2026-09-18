<?php

namespace App\Actions\Backup;

use App\Actions\Runs\CreateRunFinalizations;
use App\Actions\Runs\DispatchQueuedRun;
use App\Actions\Runs\ProcessRunFinalization;
use App\Models\ActivityLog;
use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Models\RestoreRun;
use App\Services\Agents\HostWorkAdmission;
use App\Services\Notifications\SendShoutrrrNotification;
use App\Support\DeploymentMode;
use App\Support\VolumeJobLock;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Execute one aggregated {@see BackupGroupRun}.
 *
 * Runs each active member job sequentially through the *unchanged* backup
 * pipeline ({@see RunBackup}), which keeps one archive per volume and leaves
 * restore untouched. Member runs stay silent; this action emits the single start
 * and single success/fail notification for the whole set — the behaviour the
 * grouped-backup use case (one Healthchecks endpoint for many volumes) needs.
 *
 * Sequential (not parallel) execution keeps the "stop on first failure" policy
 * trivial and makes the outcome aggregation race-free.
 */
class RunBackupGroup
{
    /** How long a member waits for its volume lock before it is marked failed. */
    private const VOLUME_LOCK_WAIT_SECONDS = 60;

    public function __construct(
        private readonly RunBackup $runBackup,
        private readonly SendShoutrrrNotification $sendShoutrrrNotification,
        private readonly CreateBackupRunRecord $createBackupRunRecord,
        private readonly WithBackupGroupMutationLocks $withGroupLocks,
        private readonly CreateRunFinalizations $createFinalizations,
    ) {}

    public function handle(BackupGroupRun $groupRun): void
    {
        if ($groupRun->member_run_ids !== null) {
            app(AdvanceBackupGroupRun::class)->handle($groupRun);

            return;
        }
        if (app(HostWorkAdmission::class)->isWaiting($groupRun)) {
            return;
        }

        DeploymentMode::assertLocalExecution();

        $startedAt = now();

        $decision = $this->withGroupLocks->handle([$groupRun->backup_job_group_id], function ($groups) use ($groupRun, $startedAt): array {
            $group = $groups->get($groupRun->backup_job_group_id);

            if (! $group instanceof BackupJobGroup) {
                return ['outcome' => 'skip'];
            }

            /** @var Collection<int, BackupJob> $members */
            $members = $group->members()->orderBy('id')->lockForUpdate()->get();

            if ($members->contains(fn (BackupJob $member): bool => $member->docker_host_id !== DockerHost::LOCAL_ID)
                || $groupRun->memberRuns()->where('docker_host_id', '!=', DockerHost::LOCAL_ID)->exists()) {
                throw new RuntimeException('Backup groups containing remote Docker hosts cannot execute locally.');
            }

            // Claim only a queued run. A running run is already owned by a worker;
            // reconciliation remains the sole owner of crashed running runs.
            $claimed = app(HostWorkAdmission::class)->claim($groupRun, [
                'status' => BackupGroupRun::STATUS_RUNNING,
                'started_at' => $startedAt,
                'last_heartbeat_at' => $startedAt,
            ]);

            if ($claimed === 0) {
                return ['outcome' => 'skip'];
            }

            $groupRun->forceFill([
                'status' => BackupGroupRun::STATUS_RUNNING,
                'started_at' => $startedAt,
                'last_heartbeat_at' => $startedAt,
            ]);

            if ($group->status === BackupJobGroup::STATUS_PAUSED) {
                $groupRun->forceFill([
                    'status' => BackupGroupRun::STATUS_CANCELLED,
                    'finished_at' => now(),
                    'error_message' => 'Group was paused before this run started.',
                ])->save();

                ActivityLog::record('backup_group_run_cancelled', 'Backup group run cancelled: the group was paused before it started.', $groupRun, [
                    'backup_job_group_id' => $group->id,
                ]);

                return ['outcome' => 'skip'];
            }

            $members = $members
                ->reject(fn (BackupJob $member): bool => $member->status === BackupJob::STATUS_PAUSED)
                ->values();

            if ($members->isNotEmpty()) {
                return ['outcome' => 'run', 'group' => $group, 'members' => $members];
            }

            $finishedAt = now();
            $groupRun->forceFill([
                'status' => BackupGroupRun::STATUS_FAILED,
                'finished_at' => $finishedAt,
                'duration_seconds' => $startedAt->diffInSeconds($finishedAt),
                'total_members' => 0,
                'error_message' => 'No runnable member volumes at run time (all were paused, detached or removed).',
            ])->save();

            $group->forceFill([
                'last_run_at' => $startedAt,
                'last_error' => 'Group run found no runnable member volumes.',
                'last_error_at' => $finishedAt,
            ])->save();

            BackupJobGroup::query()
                ->whereKey($group->id)
                ->where('status', '!=', BackupJobGroup::STATUS_PAUSED)
                ->update(['status' => BackupJobGroup::STATUS_ERROR]);

            ActivityLog::record('backup_group_run_failed', 'Backup group run had no runnable members.', $groupRun, [
                'backup_job_group_id' => $group->id,
            ]);

            $finalizationIds = $this->createFinalizations->createGroupNotifications($groupRun, $group);

            return ['outcome' => 'failed', 'finalization_ids' => $finalizationIds];
        });

        if ($decision['outcome'] === 'skip') {
            return;
        }

        if ($decision['outcome'] === 'failed') {
            app(ProcessRunFinalization::class)->dispatch($decision['finalization_ids']);

            return;
        }

        /** @var BackupJobGroup $group */
        $group = $decision['group'];
        /** @var Collection<int, BackupJob> $members */
        $members = $decision['members'];

        // Flip the group to RUNNING atomically from any non-paused, non-running
        // state (active, or error after a prior failed run — both legitimately
        // runnable). pause() flips →PAUSED with a matching `whereNot(status,
        // running)` guard, so the two conditional updates serialize on the row:
        // whichever lands first wins. If a pause won (0 rows here), honour it —
        // cancel this run without clobbering the pause or its reason.
        $flipped = BackupJobGroup::query()
            ->whereKey($group->id)
            ->whereNotIn('status', [BackupJobGroup::STATUS_PAUSED, BackupJobGroup::STATUS_RUNNING])
            ->update([
                'status' => BackupJobGroup::STATUS_RUNNING,
                'last_run_at' => $startedAt,
            ]);

        if ($flipped === 0) {
            $groupRun->forceFill([
                'status' => BackupGroupRun::STATUS_CANCELLED,
                'finished_at' => now(),
                'error_message' => 'Group was paused before this run started.',
            ])->save();

            ActivityLog::record('backup_group_run_cancelled', 'Backup group run cancelled: the group was paused before it started.', $groupRun, [
                'backup_job_group_id' => $group->id,
            ]);

            return;
        }

        $group->refresh();

        $groupRun->forceFill([
            'total_members' => $members->count(),
            'last_heartbeat_at' => now(),
        ])->save();

        ActivityLog::record('backup_group_run_started', 'Backup group run started.', $groupRun, [
            'backup_job_group_id' => $group->id,
        ]);

        $this->sendStartNotification($groupRun);

        $succeeded = 0;
        $failed = 0;

        foreach ($members as $member) {
            $memberRun = $this->runMember($groupRun, $member);

            // Re-read from the DB: the run row can be cascade-deleted mid-flight if
            // an admin removes the member job (backup_runs.backup_job_id cascades),
            // so fresh() may be null even though create() succeeded.
            $fresh = $memberRun?->fresh();

            // A member (and its run) that vanished mid-flight — create() failed, or
            // the run was cascade-deleted with its job — counts as a failure, not a
            // skip: the group did not back up everything it was configured to, so it
            // must not report a green run a dead-man's-switch would trust.
            if ($fresh !== null && $fresh->status === BackupRun::STATUS_SUCCESS) {
                $succeeded++;
            } else {
                $failed++;
            }

            // Only touches the counters + heartbeat (never status), so it cannot
            // clobber a status a concurrent reconciliation may have set.
            $groupRun->forceFill([
                'succeeded_members' => $succeeded,
                'failed_members' => $failed,
                'last_heartbeat_at' => now(),
            ])->save();

            // If reconciliation closed this run as stale while a slow member was
            // finishing (its post-backup phase leaves no queued/running member),
            // stop: that owner set the outcome and sent the notification. Creating
            // more member runs or finalizing would double-notify and race the
            // released group lock.
            if ($groupRun->fresh()->status !== BackupGroupRun::STATUS_RUNNING) {
                return;
            }

            // stop-on-first-failure: leave the remaining members un-run.
            if ($failed > 0 && $group->stopsOnFirstFailure()) {
                break;
            }
        }

        $finalizationIds = [];
        $finalized = $this->withGroupLocks->handle([$groupRun->backup_job_group_id], function ($groups) use ($groupRun, $startedAt, $succeeded, $failed, $members, &$finalizationIds): bool {
            $group = $groups->get($groupRun->backup_job_group_id);
            $lockedRun = BackupGroupRun::query()->lockForUpdate()->find($groupRun->id);

            if (! $group instanceof BackupJobGroup || $lockedRun?->status !== BackupGroupRun::STATUS_RUNNING) {
                return false;
            }

            $finishedAt = now();
            $status = $failed > 0 ? BackupGroupRun::STATUS_FAILED : BackupGroupRun::STATUS_SUCCESS;
            $lockedRun->forceFill([
                'status' => $status,
                'finished_at' => $finishedAt,
                'duration_seconds' => $startedAt->diffInSeconds($finishedAt),
                'succeeded_members' => $succeeded,
                'failed_members' => $failed,
            ])->save();

            if ($status === BackupGroupRun::STATUS_SUCCESS) {
                $group->forceFill([
                    'status' => BackupJobGroup::STATUS_ACTIVE,
                    'last_success_at' => $finishedAt,
                    'last_error' => null,
                    'last_error_at' => null,
                    'pause_reason' => null,
                ])->save();
            } else {
                $message = $failed.' of '.$members->count().' volume(s) failed to back up.';
                $group->forceFill([
                    'status' => BackupJobGroup::STATUS_ERROR,
                    'last_error' => $message,
                    'last_error_at' => $finishedAt,
                ])->save();
            }

            $finalizationIds = $this->createFinalizations->createGroupNotifications($lockedRun, $group);

            return true;
        });

        if (! $finalized) {
            return;
        }

        $groupRun->refresh();
        app(ProcessRunFinalization::class)->dispatch($finalizationIds);
    }

    /**
     * Force a group run into the FAILED state.
     *
     * Used by stale-run reconciliation. The expected status and heartbeat cutoff
     * are revalidated while the group and run rows are locked, so it loses races
     * against an in-flight claim, heartbeat, or finish.
     */
    public function markFailed(
        BackupGroupRun $groupRun,
        Throwable $exception,
        ?string $expectedStatus = null,
        ?CarbonInterface $heartbeatCutoff = null,
        ?string $expectedDispatchToken = null,
    ): bool {
        $finishedAt = now();
        $message = str($exception->getMessage() ?: 'Backup group run failed.')->limit(1000)->toString();
        $finalizationIds = [];
        $transitioned = $this->withGroupLocks->handle([$groupRun->backup_job_group_id], function ($groups) use ($groupRun, $finishedAt, $message, $expectedStatus, $heartbeatCutoff, $expectedDispatchToken, &$finalizationIds): bool {
            $group = $groups->get($groupRun->backup_job_group_id);
            $lockedRun = BackupGroupRun::query()->lockForUpdate()->find($groupRun->id);

            if (! $group instanceof BackupJobGroup || $lockedRun === null || in_array($lockedRun->status, [BackupGroupRun::STATUS_SUCCESS, BackupGroupRun::STATUS_FAILED, BackupGroupRun::STATUS_CANCELLED], true)) {
                return false;
            }

            if ($expectedStatus !== null && $lockedRun->status !== $expectedStatus) {
                return false;
            }

            if ($lockedRun->status === BackupGroupRun::STATUS_QUEUED
                && $expectedDispatchToken === null
                && ($lockedRun->dispatch_published_at !== null
                    || $lockedRun->dispatch_attempted_at?->isAfter(now()->subMinutes(DispatchQueuedRun::LEASE_MINUTES)))) {
                return false;
            }

            if ($expectedDispatchToken !== null) {
                if ($lockedRun->dispatch_token !== $expectedDispatchToken
                    || $lockedRun->dispatch_attempted_at === null
                    || $lockedRun->dispatch_published_at === null
                    || ! $lockedRun->dispatch_attempted_at->isAfter($lockedRun->dispatch_published_at)
                    || $heartbeatCutoff === null
                    || ! $lockedRun->dispatch_attempted_at->lessThan($heartbeatCutoff)) {
                    return false;
                }
            } else {
                $progressedAt = $lockedRun->last_heartbeat_at ?? $lockedRun->started_at ?? $lockedRun->created_at;

                if ($heartbeatCutoff !== null && ($progressedAt === null || ! $progressedAt->lessThan($heartbeatCutoff))) {
                    return false;
                }
            }

            $lockedRun->forceFill([
                'status' => BackupGroupRun::STATUS_FAILED,
                'finished_at' => $finishedAt,
                'duration_seconds' => ($lockedRun->started_at ?? $finishedAt)->diffInSeconds($finishedAt),
                'error_message' => $message,
            ])->save();
            $group->forceFill([
                'last_error' => $message,
                'last_error_at' => $finishedAt,
            ])->save();
            BackupJobGroup::query()
                ->whereKey($group->id)
                ->where('status', '!=', BackupJobGroup::STATUS_PAUSED)
                ->update(['status' => BackupJobGroup::STATUS_ERROR]);
            $finalizationIds = $this->createFinalizations->createGroupNotifications($lockedRun, $group);

            return true;
        });

        if (! $transitioned) {
            return false;
        }

        $groupRun->refresh();

        ActivityLog::record('backup_group_run_failed', 'Backup group run failed.', $groupRun, [
            'backup_job_group_id' => $groupRun->backup_job_group_id,
        ]);

        app(ProcessRunFinalization::class)->dispatch($finalizationIds);

        return true;
    }

    /**
     * Create and run one member backup through the unchanged pipeline. The member
     * run carries backup_group_run_id, so RunBackup keeps it silent and never
     * reschedules the member job. Every member serializes on the same overlap key
     * its standalone queue job would use — the shared volume lock for a Docker
     * volume, the per-job lock for a host-path job — so a group backup can never
     * overlap a standalone backup/restore of the same source (e.g. a run already
     * queued when the job was attached to the group). A lock held too long marks
     * just that member failed.
     */
    private function runMember(BackupGroupRun $groupRun, BackupJob $member): ?BackupRun
    {
        // Re-read the member: it was snapshotted when the group run started and may
        // have changed source, been paused, or been detached before its turn. The
        // lock below — and RunBackup, which reloads the job from the DB — must act on
        // the *current* source, or the group could lock vol_a while backing up vol_b
        // and overlap a concurrent vol_b backup/restore. A member that is gone,
        // paused, or no longer part of this group is not in this run: skip it (the
        // loop counts it as failed, so the run does not report a false success).
        $member = BackupJob::find($member->id);

        if (! $this->memberIsRunnable($member, $groupRun)) {
            ActivityLog::record('backup_group_member_skipped', 'Skipped a group member no longer runnable at its turn (deleted, paused or detached).', $groupRun, [
                'backup_job_group_id' => $groupRun->backup_job_group_id,
                'backup_job_id' => $member?->id,
            ]);

            return null;
        }

        try {
            $memberRun = $this->createBackupRunRecord->handle($member, [
                'backup_group_run_id' => $groupRun->id,
                'initiated_by_user_id' => $groupRun->initiated_by_user_id,
                'status' => BackupRun::STATUS_QUEUED,
                'trigger' => $groupRun->trigger === BackupGroupRun::TRIGGER_MANUAL
                    ? BackupRun::TRIGGER_MANUAL
                    : BackupRun::TRIGGER_SCHEDULED,
                'scheduled_for' => $groupRun->trigger === BackupGroupRun::TRIGGER_SCHEDULED
                    ? ($groupRun->scheduled_for ?? $groupRun->created_at)
                    : null,
            ]);
        } catch (Throwable $exception) {
            // The member was hard-deleted in the small window between the re-read
            // above and here. Creating its run hits a foreign-key violation; thrown
            // before the guarded block it would abort the whole loop, and the group
            // run is already RUNNING so RunBackupGroupJob::failed() would not close
            // it. Return null so the loop counts it as a failed member instead.
            ActivityLog::record('backup_group_member_skipped', 'Skipped a group member that no longer exists.', $groupRun, [
                'backup_job_group_id' => $groupRun->backup_job_group_id,
                'backup_job_id' => $member->id,
            ]);

            return null;
        }

        $volume = $memberRun->sourceVolumeName();
        // The exact cache key RunBackupJob's WithoutOverlapping middleware uses, so
        // an in-process group member contends with a standalone queue job: the
        // volume lock for a Docker volume, else the per-job lock a host-path run
        // falls back to.
        $lockKey = VolumeJobLock::cacheKeyFor(VolumeJobLock::key($volume, 'backup-job-'.$memberRun->backup_job_id, $memberRun->docker_host_id));

        try {
            Cache::lock($lockKey, 86400)
                ->block(self::VOLUME_LOCK_WAIT_SECONDS, function () use ($memberRun, $member, $groupRun, $lockKey): void {
                    // Re-read after acquiring the lock: the member's source can change
                    // during the (up to 60s) wait, and RunBackup reloads the job. If
                    // it no longer maps to the lock we actually hold — or the member
                    // is no longer runnable — running would back up an unlocked
                    // volume, so skip it and retry next group run.
                    $current = BackupJob::find($member->id);

                    if (! $this->memberIsRunnable($current, $groupRun) || $this->lockKeyFor($current) !== $lockKey) {
                        // The member was paused, detached, deleted or had its source
                        // changed while we waited for the lock. Cancel this run
                        // WITHOUT markFailed(): markFailed flips the job to ERROR,
                        // which for a paused member would replace its PAUSED status
                        // and let runnableMembers() pick it up again next run. The
                        // group loop counts a cancelled run as a failure regardless.
                        $this->cancelMemberRun($memberRun, 'Member "'.$member->name.'" was paused, detached or changed while waiting for its lock; skipped in this group run.');

                        return;
                    }

                    // Holding the lock already excludes a running backup/restore;
                    // this also mirrors RunBackupJob's volumeBusy guard for a
                    // terminal run that still has containers stopped (pending
                    // restart/reconcile) or a run that outlived the 24h lock TTL, so
                    // we never overlap it.
                    $volume = $current->isDockerVolumeSource() ? $current->volume_name : null;

                    if (filled($volume)) {
                        if ($this->volumeBusy($volume, $memberRun->id, $memberRun->docker_host_id)) {
                            throw new RuntimeException('Volume "'.$volume.'" is not ready (a previous run still has containers stopped); skipped in this group run.');
                        }
                    } elseif ($this->jobBusy($current->id, $memberRun->id, $memberRun->docker_host_id)) {
                        throw new RuntimeException('Job "'.$current->name.'" is not ready (a previous run of it is still in progress); skipped in this group run.');
                    }

                    $this->runBackup->handle($memberRun, acceptedOperation: true);
                });
        } catch (LockTimeoutException) {
            // If the member became unrunnable (paused, detached, deleted) while we
            // waited for the lock, cancel without markFailed — markFailed flips the
            // job to ERROR, and a paused member flipped to ERROR would be picked up
            // again by runnableMembers on the next run. Otherwise it is a genuine
            // "volume/job still busy" skip.
            $current = BackupJob::find($member->id);

            if (! $this->memberIsRunnable($current, $groupRun)) {
                $this->cancelMemberRun($memberRun, 'Member "'.$member->name.'" was paused, detached or deleted while waiting for its lock; skipped in this group run.');
            } else {
                $this->runBackup->markFailed(
                    $memberRun,
                    new RuntimeException(filled($volume)
                        ? 'Volume "'.$volume.'" was busy; skipped in this group run.'
                        : 'A concurrent run of this job was in progress; skipped in this group run.'),
                    BackupRun::STATUS_QUEUED,
                );
            }
        } catch (Throwable $exception) {
            // RunBackup normally swallows backup failures and marks the run itself;
            // this guards against an unexpected throw so one member cannot abort the
            // whole group.
            $expectedStatus = $memberRun->fresh()?->status;

            if (in_array($expectedStatus, [BackupRun::STATUS_QUEUED, BackupRun::STATUS_RUNNING], true)) {
                $this->runBackup->markFailed($memberRun, $exception, $expectedStatus);
            }
        }

        return $memberRun;
    }

    /**
     * Whether another backup or restore still holds the volume: one that is
     * running, or one that reached a terminal state but still has containers it
     * stopped awaiting restart. Mirrors RunBackupJob::volumeBusy so an in-process
     * member run applies the same guard queued standalone jobs do.
     */
    private function volumeBusy(string $volume, int $exceptBackupRunId, int $dockerHostId): bool
    {
        $backupBusy = BackupRun::query()
            ->where('docker_host_id', $dockerHostId)
            ->where(function ($query) use ($volume): void {
                $query->where('source_volume_name', $volume)
                    ->orWhere(fn ($query) => $query
                        ->whereNull('source_type_snapshot')
                        ->whereHas('job', fn ($query) => $query->where('volume_name', $volume)));
            })
            ->whereKeyNot($exceptBackupRunId)
            ->where(fn ($query) => $this->stillWorking($query, includeBackupCleanup: true))
            ->exists();

        $restoreBusy = RestoreRun::query()
            ->where('target_docker_host_id', $dockerHostId)
            ->where('target_volume_name', $volume)
            ->where(fn ($query) => $this->stillWorking($query))
            ->exists();

        return $backupBusy || $restoreBusy;
    }

    /**
     * Host-path counterpart of {@see volumeBusy()}: whether another backup run of
     * the same job is still working (running, or terminal but mid-restart of its
     * containers). Host-path members serialize on their per-job lock, which
     * restores never share, so only sibling backup runs count.
     */
    private function jobBusy(int $jobId, int $exceptBackupRunId, int $dockerHostId): bool
    {
        return BackupRun::query()
            ->where('docker_host_id', $dockerHostId)
            ->where('backup_job_id', $jobId)
            ->whereKeyNot($exceptBackupRunId)
            ->where(fn ($query) => $this->stillWorking($query, includeBackupCleanup: true))
            ->exists();
    }

    private function stillWorking($query, bool $includeBackupCleanup = false): void
    {
        $query
            ->where('status', BackupRun::STATUS_RUNNING)
            ->orWhere(fn ($q) => $q->whereNotNull('stopped_container_ids')->whereJsonLength('stopped_container_ids', '>', 0));

        if ($includeBackupCleanup) {
            $query->orWhere('docker_container_cleanup_pending', true);
        }
    }

    /**
     * Cancel a member run without touching its job. Used when a member becomes
     * unrunnable (paused/detached/deleted/source-changed) between the snapshot and
     * its turn: unlike RunBackup::markFailed this leaves the job's status alone, so
     * a paused member is not flipped to ERROR and silently made runnable again. The
     * group loop still counts a cancelled run as a failure.
     */
    private function cancelMemberRun(BackupRun $run, string $reason): void
    {
        $run->forceFill([
            'status' => BackupRun::STATUS_CANCELLED,
            'finished_at' => now(),
            'error_message' => $reason,
        ])->save();

        ActivityLog::record('backup_group_member_skipped', $reason, $run, [
            'backup_job_id' => $run->backup_job_id,
            'backup_group_run_id' => $run->backup_group_run_id,
        ]);
    }

    /**
     * The WithoutOverlapping cache key a member serializes on — the shared volume
     * lock for a Docker-volume source, the per-job lock for a host-path source.
     * Derived from the passed job's current source so it can be recomputed after a
     * lock wait to detect a source change.
     */
    private function lockKeyFor(BackupJob $member): string
    {
        return VolumeJobLock::cacheKeyFor(VolumeJobLock::key(
            $member->isDockerVolumeSource() ? $member->volume_name : null,
            'backup-job-'.$member->id,
            $member->docker_host_id,
        ));
    }

    /**
     * Whether a job is still a runnable member of this group run: it exists, still
     * belongs to this group, and is not paused. Re-checked at the member's turn and
     * again after its lock is acquired, since a member snapshotted at run start can
     * be deleted, paused or detached before it runs.
     */
    private function memberIsRunnable(?BackupJob $member, BackupGroupRun $groupRun): bool
    {
        return $member !== null
            && $member->docker_host_id === DockerHost::LOCAL_ID
            && $member->backup_job_group_id === $groupRun->backup_job_group_id
            && $member->status !== BackupJob::STATUS_PAUSED;
    }

    private function sendStartNotification(BackupGroupRun $groupRun): void
    {
        try {
            // No member container exists yet at group start; refresh the group run's
            // heartbeat per channel so slow start notifications don't get the live
            // group run reconciled as stale.
            $this->sendShoutrrrNotification->sendGroupRunStarted($groupRun, fn () => $this->touchHeartbeat($groupRun));
        } catch (Throwable $exception) {
            ActivityLog::record('notification_send_failed', 'Backup group start notification failed.', $groupRun, [
                'error' => str($exception->getMessage())->limit(1000)->toString(),
            ]);
        }
    }

    /** Refresh the group run's liveness marker (used around slow notifications). */
    private function touchHeartbeat(BackupGroupRun $groupRun): void
    {
        BackupGroupRun::query()
            ->whereKey($groupRun->getKey())
            ->update(['last_heartbeat_at' => now()]);
    }
}
