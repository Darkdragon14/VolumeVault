<?php

namespace App\Console\Commands;

use App\Actions\Backup\RunBackup;
use App\Actions\Docker\ContainerIsAlive;
use App\Actions\Restore\RunRestore;
use App\Models\BackupRun;
use App\Models\RestoreRun;
use App\Support\VolumeJobLock;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class ReconcileStaleRuns extends Command
{
    protected $signature = 'volumevault:reconcile-stale-runs
        {--minutes= : Age threshold in minutes before a queued/running run with no live container is considered stale}';

    protected $description = 'Mark backup/restore runs stuck in queued/running as failed after a worker crash, timeout or restart, and restart application containers left stopped by an interrupted backup.';

    /**
     * Age threshold for runs that can't be checked for liveness (queued runs a
     * worker never picked up, or running runs whose container was never
     * created). Running runs that DID record a container are reconciled purely
     * on container liveness, so a legitimately long backup is never failed and
     * this threshold can stay short.
     */
    public const DEFAULT_THRESHOLD_MINUTES = 15;

    public function __construct(private readonly ContainerIsAlive $containerIsAlive)
    {
        parent::__construct();
    }

    public function handle(RunBackup $runBackup, RunRestore $runRestore): int
    {
        $minutes = (int) ($this->option('minutes') ?: self::DEFAULT_THRESHOLD_MINUTES);

        if ($minutes < 1) {
            $minutes = self::DEFAULT_THRESHOLD_MINUTES;
        }

        $cutoff = now()->subMinutes($minutes);
        $reason = "Run reconciled as failed: stuck in queued/running for more than {$minutes} minute(s) (possible worker crash, timeout or restart).";

        $backupCount = 0;
        $this->staleBackupRuns($cutoff)->each(function (BackupRun $run) use ($runBackup, $reason, &$backupCount): void {
            $wasRunning = $run->status === BackupRun::STATUS_RUNNING;
            $lockKey = VolumeJobLock::key($run->job?->volume_name, 'backup-job-'.$run->backup_job_id);

            // Only release the lock when we actually failed a run that was running:
            // a no-op markFailed (the run finished first) means the lock may now be
            // held by the next job — releasing it would break serialization.
            if ($runBackup->markFailed($run, new RuntimeException($reason)) && $wasRunning) {
                $this->releaseLock($lockKey);
            }
            $backupCount++;
        });

        $restoreCount = 0;
        $this->staleRestoreRuns($cutoff)->each(function (RestoreRun $run) use ($runRestore, $reason, &$restoreCount): void {
            $wasRunning = $run->status === RestoreRun::STATUS_RUNNING;
            $lockKey = VolumeJobLock::key($run->target_volume_name, 'restore-run-'.$run->id);

            if ($runRestore->markFailed($run, new RuntimeException($reason)) && $wasRunning) {
                $this->releaseLock($lockKey);
            }
            $restoreCount++;
        });

        // Runs the sweep just failed (or runs whose worker died during restart)
        // may still have application containers stopped. Restart them now.
        $restartedCount = 0;
        $this->backupRunsWithStoppedContainers()->each(function (BackupRun $run) use ($runBackup, &$restartedCount): void {
            try {
                $runBackup->restartStoppedContainers($run);
                $restartedCount++;
            } catch (Throwable $exception) {
                $this->warn("Failed to restart containers for backup run {$run->id}: {$exception->getMessage()}");
            }
        });

        $this->restoreRunsWithStoppedContainers()->each(function (RestoreRun $run) use ($runRestore, &$restartedCount): void {
            try {
                $runRestore->restartStoppedContainers($run);
                $restartedCount++;
            } catch (Throwable $exception) {
                $this->warn("Failed to restart containers for restore run {$run->id}: {$exception->getMessage()}");
            }
        });

        $this->info("Reconciled {$backupCount} stale backup run(s) and {$restoreCount} stale restore run(s); restarted containers for {$restartedCount} interrupted run(s).");

        return self::SUCCESS;
    }

    /** @return Collection<int, BackupRun> */
    private function staleBackupRuns(CarbonInterface $cutoff): Collection
    {
        return BackupRun::query()
            ->whereIn('status', [BackupRun::STATUS_QUEUED, BackupRun::STATUS_RUNNING])
            ->where(fn ($query) => $this->candidateConstraint($query, $cutoff, BackupRun::STATUS_RUNNING))
            ->with('job')
            ->get()
            ->filter(fn (BackupRun $run) => $this->isStale($run, $cutoff, BackupRun::STATUS_RUNNING)
                && ! $this->backupIsWaitingForVolumeLock($run));
    }

    /** @return Collection<int, RestoreRun> */
    private function staleRestoreRuns(CarbonInterface $cutoff): Collection
    {
        return RestoreRun::query()
            ->whereIn('status', [RestoreRun::STATUS_QUEUED, RestoreRun::STATUS_RUNNING])
            ->where(fn ($query) => $this->candidateConstraint($query, $cutoff, RestoreRun::STATUS_RUNNING))
            ->get()
            ->filter(fn (RestoreRun $run) => $this->isStale($run, $cutoff, RestoreRun::STATUS_RUNNING)
                && ! $this->restoreIsProgressing($run, $cutoff)
                && ! $this->volumeHeldByAnotherActiveRun($run->target_volume_name, restoreId: $run->id));
    }

    /**
     * Force-release the WithoutOverlapping lock a crashed RUNNING holder left
     * behind. The lock has a 24h expiry, so without this a crash would block every
     * same-key backup/restore — and keep failing released waiters — for up to a
     * day. The key is the same one the job locked on, so this also frees
     * volume-less holders (host-path backups, which lock on backup-job-{id}).
     */
    private function releaseLock(string $lockKey): void
    {
        Cache::lock(VolumeJobLock::cacheKeyFor($lockKey))->forceRelease();
    }

    /**
     * Whether a stale backup run should be left alone because it is a queued job
     * waiting on a volume lock held by another active run.
     *
     * A pre-restore safety backup is exempt: it runs inline inside its restore's
     * worker (never as a separately-queued job), so it is never a lock-waiter.
     * Exempting it also breaks a mutual-skip deadlock — its parent restore stays
     * "running" and would otherwise shield the safety backup from reconciliation,
     * while the restore sweep in turn shields the restore because the safety
     * backup is still "running". Reconciling the safety backup on its own liveness
     * frees both: once it is failed, the restore is no longer seen as progressing.
     */
    private function backupIsWaitingForVolumeLock(BackupRun $run): bool
    {
        if ($run->trigger === BackupRun::TRIGGER_PRE_RESTORE) {
            return false;
        }

        return $this->volumeHeldByAnotherActiveRun($run->job?->volume_name, backupRunId: $run->id);
    }

    /**
     * Whether another backup or restore on $volume holds — or only just released —
     * the volume lock. A queued run released by WithoutOverlapping while it waits
     * for that lock is legitimately pending, not stale; failing it would lose the
     * operation and (with the terminal-state guard) leave a confusingly failed run
     * with a queued job still in flight.
     *
     * "Just released" matters because a lock loser is requeued with releaseAfter(60):
     * for up to that delay the holder has finished (no RUNNING run) yet the waiter
     * keeps its old created_at and has not resumed. Treating a holder that reached a
     * terminal state within the release window + a buffer as still "busy" bridges
     * that gap, so the waiter survives long enough to pick the lock back up.
     */
    private function volumeHeldByAnotherActiveRun(?string $volume, ?int $restoreId = null, ?int $backupRunId = null): bool
    {
        if (! filled($volume)) {
            return false;
        }

        // releaseAfter(60) on both jobs + a buffer for the worker to redeliver.
        $recentlyReleased = now()->subSeconds(120);

        $restoreHolds = RestoreRun::query()
            ->where('target_volume_name', $volume)
            ->when($restoreId, fn ($query) => $query->whereKeyNot($restoreId))
            ->where(fn ($query) => $this->stillHoldsVolume($query, $recentlyReleased))
            ->exists();

        $backupHolds = BackupRun::query()
            ->whereHas('job', fn ($query) => $query->where('volume_name', $volume))
            ->when($backupRunId, fn ($query) => $query->whereKeyNot($backupRunId))
            // A pre-restore safety backup runs inline inside its restore's worker
            // and never holds the volume lock on its own, so it must not count as a
            // holder — otherwise a just-failed safety backup would shield its parent
            // restore from reconciliation, re-creating the mutual-skip deadlock.
            ->where('trigger', '!=', BackupRun::TRIGGER_PRE_RESTORE)
            ->where(fn ($query) => $this->stillHoldsVolume($query, $recentlyReleased))
            ->exists();

        return $restoreHolds || $backupHolds;
    }

    /**
     * Constrain to runs that still hold the volume: running, terminal but only
     * just released (within the requeue window), or terminal but still owning
     * stopped containers their finally has not restarted yet. The last case keeps
     * this consistent with the jobs' volumeBusy guard, so a queued waiter is not
     * swept while a terminal run is still mid-restart/reconcile of its containers.
     *
     * STATUS_* values are identical across BackupRun and RestoreRun, so the same
     * constants apply to either query.
     */
    private function stillHoldsVolume($query, CarbonInterface $recentlyReleased): void
    {
        $query
            ->where('status', BackupRun::STATUS_RUNNING)
            ->orWhere(fn ($q) => $q
                ->whereIn('status', [BackupRun::STATUS_SUCCESS, BackupRun::STATUS_FAILED, BackupRun::STATUS_CANCELLED])
                ->where('finished_at', '>=', $recentlyReleased))
            ->orWhere(fn ($q) => $q
                ->whereNotNull('stopped_container_ids')
                ->where('stopped_container_ids', '!=', '[]'));
    }

    /**
     * Terminal backup runs whose application containers were stopped for the
     * backup but never restarted (worker crash between stop and restart).
     *
     * @return Collection<int, BackupRun>
     */
    private function backupRunsWithStoppedContainers(): Collection
    {
        return BackupRun::query()
            ->whereIn('status', [BackupRun::STATUS_SUCCESS, BackupRun::STATUS_FAILED, BackupRun::STATUS_CANCELLED])
            ->whereNotNull('stopped_container_ids')
            ->where('stopped_container_ids', '!=', '[]')
            ->get();
    }

    /**
     * Terminal restore runs whose application containers were stopped for a safe
     * in-place restore but never restarted (worker crash between stop and
     * restart). Mirrors {@see backupRunsWithStoppedContainers()}.
     *
     * @return Collection<int, RestoreRun>
     */
    private function restoreRunsWithStoppedContainers(): Collection
    {
        return RestoreRun::query()
            ->whereIn('status', [RestoreRun::STATUS_SUCCESS, RestoreRun::STATUS_FAILED, RestoreRun::STATUS_CANCELLED])
            ->whereNotNull('stopped_container_ids')
            ->where('stopped_container_ids', '!=', '[]')
            ->get();
    }

    /**
     * Narrow the candidate set before the per-run liveness decision: every
     * running run (its container is checked for liveness regardless of age) plus
     * queued runs old enough to count as never picked up by a worker.
     */
    private function candidateConstraint($query, CarbonInterface $cutoff, string $runningStatus): void
    {
        $query
            ->where('status', $runningStatus)
            ->orWhere(fn ($q) => $q->where('status', '!=', $runningStatus)->where('created_at', '<', $cutoff));
    }

    /**
     * Restore-only liveness for the long phases that run before any Docker
     * container (and thus any liveness-checkable docker_container_id) exists:
     * the optional safety backup and the archive download. Either can legitimately
     * exceed the short stale threshold, so a restore actively working through them
     * must not be reconciled as dead.
     */
    private function restoreIsProgressing(RestoreRun $run, CarbonInterface $cutoff): bool
    {
        if ($run->status !== RestoreRun::STATUS_RUNNING) {
            return false;
        }

        // Safety backup in flight: it runs as its own BackupRun, reconciled on its
        // own liveness/age, so the restore waiting on it is not itself stale.
        if ($run->pre_restore_backup_run_id !== null) {
            $backup = $run->preRestoreBackup()->first();

            if ($backup && in_array($backup->status, [BackupRun::STATUS_QUEUED, BackupRun::STATUS_RUNNING], true)) {
                return true;
            }
        }

        // Archive download in flight: the worker is still streaming the archive to
        // this run's temp file, so the OS keeps advancing its mtime. A dead worker
        // leaves it cold. Only meaningful BEFORE any container exists — once a
        // verify/clear/extract container is recorded, its liveness (checked by
        // isStale) is authoritative, and a leftover backup.tar.gz from the finished
        // download must not mask a container already confirmed dead.
        if (filled($run->docker_container_id)) {
            return false;
        }

        $archivePath = storage_path('app/restore-runs/'.$run->id.'/backup.tar.gz');

        return is_file($archivePath) && filemtime($archivePath) >= $cutoff->getTimestamp();
    }

    /**
     * Decide whether a candidate run is genuinely dead.
     *
     * A running run that recorded a Docker container is reconciled only when
     * that container is confirmed gone — the backup/restore container runs with
     * `--rm`, so a missing container means the process is gone, while a live one
     * means a long but healthy run we must leave untouched. When Docker can't be
     * reached (indeterminate liveness), we fall back to the age threshold rather
     * than failing a healthy recent run on a transient blip. Running runs with no
     * recorded container (worker died between marking running and creating the
     * container) and queued runs also fall back to the age threshold.
     */
    private function isStale(Model $run, CarbonInterface $cutoff, string $runningStatus): bool
    {
        if ($run->status === $runningStatus) {
            if (filled($run->docker_container_id)) {
                $alive = $this->containerIsAlive->handle($run->docker_container_id);

                if ($alive !== null) {
                    return $alive === false;
                }
                // Indeterminate (Docker unreachable): fall through to the age gate.
            }

            // No live container yet (worker died between marking running and
            // creating the container) — or Docker was unreachable. Prefer the
            // progress heartbeat when the run records one: a restore spends its
            // pre-container time on a safety backup and archive download, each of
            // which refreshes last_heartbeat_at, so a slow-but-healthy restore is
            // not failed on the short default threshold. Backup runs have no
            // heartbeat column and fall back to started_at as before.
            $progressedAt = $run->last_heartbeat_at ?? $run->started_at ?? $run->created_at;

            return $progressedAt !== null && $progressedAt->lessThan($cutoff);
        }

        return $run->created_at !== null && $run->created_at->lessThan($cutoff);
    }
}
