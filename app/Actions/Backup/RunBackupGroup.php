<?php

namespace App\Actions\Backup;

use App\Models\ActivityLog;
use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\RestoreRun;
use App\Services\Notifications\SendShoutrrrNotification;
use App\Support\VolumeJobLock;
use Illuminate\Contracts\Cache\LockTimeoutException;
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
    ) {}

    public function handle(BackupGroupRun $groupRun): void
    {
        $startedAt = now();

        // Atomically claim the run (non-terminal → running) so a redelivery or a
        // reconciliation race cannot execute it twice. Mirrors RunBackup.
        // Claim only a queued run. A run already RUNNING is owned by a worker
        // (its timeout is 0, so a long sequential group is legitimate); a copy
        // redelivered after the 24h lock TTL must not re-run it and overlap. A
        // crashed RUNNING run is not resumed here — reconciliation closes it and
        // the next scheduled run starts fresh.
        $claimed = BackupGroupRun::query()
            ->whereKey($groupRun->getKey())
            ->where('status', BackupGroupRun::STATUS_QUEUED)
            ->update([
                'status' => BackupGroupRun::STATUS_RUNNING,
                'started_at' => $startedAt,
                'last_heartbeat_at' => $startedAt,
            ]);

        if ($claimed === 0) {
            return;
        }

        $groupRun->refresh();
        $groupRun->loadMissing('group');
        $group = $groupRun->group;

        if ($group === null) {
            return;
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, BackupJob> $members */
        $members = $group->runnableMembers()->orderBy('id')->get();

        // The run was created with runnable members, but they were all paused,
        // detached or removed before the worker started. Fail the run instead of
        // reporting a false success that would back up nothing yet turn a
        // dead-man's-switch monitor green. No start notification is sent — the run
        // never begins — only the aggregated failure.
        if ($members->isEmpty()) {
            $finishedAt = now();

            $groupRun->forceFill([
                'status' => BackupGroupRun::STATUS_FAILED,
                'finished_at' => $finishedAt,
                'duration_seconds' => $startedAt->diffInSeconds($finishedAt),
                'total_members' => 0,
                'error_message' => 'No runnable member volumes at run time (all were paused, detached or removed).',
            ])->save();

            $group->forceFill([
                'status' => BackupJobGroup::STATUS_ERROR,
                'last_run_at' => $startedAt,
                'last_error' => 'Group run found no runnable member volumes.',
                'last_error_at' => $finishedAt,
            ])->save();

            ActivityLog::record('backup_group_run_failed', 'Backup group run had no runnable members.', $groupRun, [
                'backup_job_group_id' => $group->id,
            ]);

            $this->sendFinishNotification($groupRun->fresh('group'));

            return;
        }

        $group->forceFill([
            'status' => BackupJobGroup::STATUS_RUNNING,
            'last_run_at' => $startedAt,
        ])->save();

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

            // A member deleted after the snapshot returns null (logged in runMember):
            // it never ran, so it neither succeeds nor fails the group.
            if ($memberRun !== null) {
                if ($memberRun->fresh()->status === BackupRun::STATUS_SUCCESS) {
                    $succeeded++;
                } else {
                    $failed++;
                }
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

        $finishedAt = now();
        $status = $failed > 0 ? BackupGroupRun::STATUS_FAILED : BackupGroupRun::STATUS_SUCCESS;

        // Atomic finalization: only if we still own the run. A conditional UPDATE
        // (running → terminal) loses the race to a reconciliation that already
        // failed it, rather than overwriting that outcome and firing a second,
        // contradictory notification.
        $finalized = BackupGroupRun::query()
            ->whereKey($groupRun->getKey())
            ->where('status', BackupGroupRun::STATUS_RUNNING)
            ->update([
                'status' => $status,
                'finished_at' => $finishedAt,
                'duration_seconds' => $startedAt->diffInSeconds($finishedAt),
                'succeeded_members' => $succeeded,
                'failed_members' => $failed,
            ]);

        if ($finalized === 0) {
            return;
        }

        $groupRun->refresh();

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

        $this->sendFinishNotification($groupRun->fresh('group'));
    }

    /**
     * Force a group run into the FAILED state.
     *
     * Shared by the queue job's failed() hook (worker timeout / restart) and by
     * stale-run reconciliation. Conditional UPDATE (non-terminal → failed) so it
     * loses the race against an in-flight finish rather than overwriting it.
     */
    public function markFailed(BackupGroupRun $groupRun, Throwable $exception): bool
    {
        $finishedAt = now();
        $startedAt = $groupRun->started_at ?? $finishedAt;
        $message = str($exception->getMessage() ?: 'Backup group run failed.')->limit(1000)->toString();

        $transitioned = BackupGroupRun::query()
            ->whereKey($groupRun->getKey())
            ->whereNotIn('status', [BackupGroupRun::STATUS_SUCCESS, BackupGroupRun::STATUS_FAILED, BackupGroupRun::STATUS_CANCELLED])
            ->update([
                'status' => BackupGroupRun::STATUS_FAILED,
                'finished_at' => $finishedAt,
                'duration_seconds' => $startedAt->diffInSeconds($finishedAt),
                'error_message' => $message,
            ]);

        if ($transitioned === 0) {
            return false;
        }

        $groupRun->refresh();
        $groupRun->loadMissing('group');

        if ($groupRun->group) {
            $groupRun->group->forceFill([
                'status' => BackupJobGroup::STATUS_ERROR,
                'last_error' => $message,
                'last_error_at' => $finishedAt,
            ])->save();
        }

        ActivityLog::record('backup_group_run_failed', 'Backup group run failed.', $groupRun, [
            'backup_job_group_id' => $groupRun->backup_job_group_id,
        ]);

        $this->sendFinishNotification($groupRun);

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
        try {
            $memberRun = BackupRun::create([
                'backup_job_id' => $member->id,
                'backup_group_run_id' => $groupRun->id,
                'initiated_by_user_id' => $groupRun->initiated_by_user_id,
                'status' => BackupRun::STATUS_QUEUED,
                'trigger' => $groupRun->trigger === BackupGroupRun::TRIGGER_MANUAL
                    ? BackupRun::TRIGGER_MANUAL
                    : BackupRun::TRIGGER_SCHEDULED,
            ]);
        } catch (Throwable $exception) {
            // The member was hard-deleted after the run's member snapshot (pause and
            // detach are already filtered out by runnableMembers). Creating its run
            // hits a foreign-key violation; thrown here — before the guarded block
            // below — it would abort the whole loop, and the group run is already
            // RUNNING so RunBackupGroupJob::failed() would not close it, leaving it
            // stuck until reconciliation. Skip the vanished member instead.
            ActivityLog::record('backup_group_member_skipped', 'Skipped a group member that no longer exists.', $groupRun, [
                'backup_job_group_id' => $groupRun->backup_job_group_id,
                'backup_job_id' => $member->id,
            ]);

            return null;
        }

        $volume = $member->isDockerVolumeSource() ? $member->volume_name : null;

        // The exact cache key RunBackupJob's WithoutOverlapping middleware uses, so
        // an in-process group member contends with a standalone queue job: the
        // volume lock for a Docker volume, else the per-job lock a host-path run
        // falls back to.
        $lockKey = filled($volume)
            ? VolumeJobLock::cacheKey($volume)
            : VolumeJobLock::cacheKeyFor('backup-job-'.$member->id);

        try {
            Cache::lock($lockKey, 86400)
                ->block(self::VOLUME_LOCK_WAIT_SECONDS, function () use ($memberRun, $member, $volume): void {
                    // Holding the lock already excludes a running backup/restore;
                    // this also mirrors RunBackupJob's volumeBusy guard for a
                    // terminal run that still has containers stopped (pending
                    // restart/reconcile) or a run that outlived the 24h lock TTL, so
                    // we never overlap it. Keyed on the volume for a Docker-volume
                    // member, on the job for a host-path member. Retried next run.
                    if (filled($volume)) {
                        if ($this->volumeBusy($volume, $memberRun->id)) {
                            throw new RuntimeException('Volume "'.$volume.'" is not ready (a previous run still has containers stopped); skipped in this group run.');
                        }
                    } elseif ($this->jobBusy($member->id, $memberRun->id)) {
                        throw new RuntimeException('Job "'.$member->name.'" is not ready (a previous run of it is still in progress); skipped in this group run.');
                    }

                    $this->runBackup->handle($memberRun);
                });
        } catch (LockTimeoutException) {
            $this->runBackup->markFailed(
                $memberRun,
                new RuntimeException(filled($volume)
                    ? 'Volume "'.$volume.'" was busy; skipped in this group run.'
                    : 'A concurrent run of this job was in progress; skipped in this group run.'),
            );
        } catch (Throwable $exception) {
            // RunBackup normally swallows backup failures and marks the run itself;
            // this guards against an unexpected throw so one member cannot abort the
            // whole group.
            $this->runBackup->markFailed($memberRun, $exception);
        }

        return $memberRun;
    }

    /**
     * Whether another backup or restore still holds the volume: one that is
     * running, or one that reached a terminal state but still has containers it
     * stopped awaiting restart. Mirrors RunBackupJob::volumeBusy so an in-process
     * member run applies the same guard queued standalone jobs do.
     */
    private function volumeBusy(string $volume, int $exceptBackupRunId): bool
    {
        $backupBusy = BackupRun::query()
            ->whereHas('job', fn ($query) => $query->where('volume_name', $volume))
            ->whereKeyNot($exceptBackupRunId)
            ->where(fn ($query) => $this->stillWorking($query))
            ->exists();

        $restoreBusy = RestoreRun::query()
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
    private function jobBusy(int $jobId, int $exceptBackupRunId): bool
    {
        return BackupRun::query()
            ->where('backup_job_id', $jobId)
            ->whereKeyNot($exceptBackupRunId)
            ->where(fn ($query) => $this->stillWorking($query))
            ->exists();
    }

    private function stillWorking($query): void
    {
        $query
            ->where('status', BackupRun::STATUS_RUNNING)
            ->orWhere(fn ($q) => $q->whereNotNull('stopped_container_ids')->where('stopped_container_ids', '!=', '[]'));
    }

    private function sendStartNotification(BackupGroupRun $groupRun): void
    {
        try {
            $this->sendShoutrrrNotification->sendGroupRunStarted($groupRun);
        } catch (Throwable $exception) {
            ActivityLog::record('notification_send_failed', 'Backup group start notification failed.', $groupRun, [
                'error' => str($exception->getMessage())->limit(1000)->toString(),
            ]);
        }
    }

    private function sendFinishNotification(BackupGroupRun $groupRun): void
    {
        try {
            $this->sendShoutrrrNotification->sendGroupRunFinished($groupRun);
        } catch (Throwable $exception) {
            ActivityLog::record('notification_send_failed', 'Backup group notification failed.', $groupRun, [
                'error' => str($exception->getMessage())->limit(1000)->toString(),
            ]);
        }
    }
}
