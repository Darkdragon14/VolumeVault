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
        $claimed = BackupGroupRun::query()
            ->whereKey($groupRun->getKey())
            ->whereNotIn('status', [BackupGroupRun::STATUS_SUCCESS, BackupGroupRun::STATUS_FAILED, BackupGroupRun::STATUS_CANCELLED])
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

            if ($memberRun->fresh()->status === BackupRun::STATUS_SUCCESS) {
                $succeeded++;
            } else {
                $failed++;
            }

            $groupRun->forceFill([
                'succeeded_members' => $succeeded,
                'failed_members' => $failed,
                'last_heartbeat_at' => now(),
            ])->save();

            // stop-on-first-failure: leave the remaining members un-run.
            if ($failed > 0 && $group->stopsOnFirstFailure()) {
                break;
            }
        }

        $finishedAt = now();
        $status = $failed > 0 ? BackupGroupRun::STATUS_FAILED : BackupGroupRun::STATUS_SUCCESS;

        $groupRun->forceFill([
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
     * reschedules the member job. Docker-volume members serialize on the shared
     * volume lock so a group backup cannot overlap a standalone backup/restore of
     * the same volume; a volume held too long marks just that member failed.
     */
    private function runMember(BackupGroupRun $groupRun, BackupJob $member): BackupRun
    {
        $memberRun = BackupRun::create([
            'backup_job_id' => $member->id,
            'backup_group_run_id' => $groupRun->id,
            'initiated_by_user_id' => $groupRun->initiated_by_user_id,
            'status' => BackupRun::STATUS_QUEUED,
            'trigger' => $groupRun->trigger === BackupGroupRun::TRIGGER_MANUAL
                ? BackupRun::TRIGGER_MANUAL
                : BackupRun::TRIGGER_SCHEDULED,
        ]);

        $volume = $member->isDockerVolumeSource() ? $member->volume_name : null;

        try {
            if (filled($volume)) {
                Cache::lock(VolumeJobLock::cacheKey($volume), 86400)
                    ->block(self::VOLUME_LOCK_WAIT_SECONDS, function () use ($memberRun, $volume): void {
                        // Holding the shared volume lock already excludes a running
                        // backup/restore; this also mirrors RunBackupJob's volumeBusy
                        // guard for a terminal run that still has containers stopped
                        // (pending restart/reconcile), so we never read a volume whose
                        // apps are still down. The member is retried next group run.
                        if ($this->volumeBusy($volume, $memberRun->id)) {
                            throw new RuntimeException('Volume "'.$volume.'" is not ready (a previous run still has containers stopped); skipped in this group run.');
                        }

                        $this->runBackup->handle($memberRun);
                    });
            } else {
                $this->runBackup->handle($memberRun);
            }
        } catch (LockTimeoutException) {
            $this->runBackup->markFailed(
                $memberRun,
                new RuntimeException('Volume "'.$volume.'" was busy; skipped in this group run.'),
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
