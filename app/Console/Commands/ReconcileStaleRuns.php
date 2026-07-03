<?php

namespace App\Console\Commands;

use App\Actions\Backup\RunBackup;
use App\Actions\Backup\RunBackupGroup;
use App\Actions\Docker\ContainerIsAlive;
use App\Actions\Restore\RunRestore;
use App\Models\BackupGroupRun;
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

    public function handle(RunBackup $runBackup, RunRestore $runRestore, RunBackupGroup $runBackupGroup): int
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
            $volume = $run->job?->volume_name;
            $lockKey = VolumeJobLock::key($volume, 'backup-job-'.$run->backup_job_id);

            if ($runBackup->markFailed($run, new RuntimeException($reason))) {
                // A running holder definitely held the lock. But WithoutOverlapping
                // acquires the lock *before* RunBackup flips the row to running, so a
                // worker that crashed in that window (or a group member, which takes
                // the lock in-process) leaves a "queued" row holding the lock for the
                // full 24h TTL. Release that too — but only when no other run
                // currently holds the same lock, so we never steal a live holder's
                // (a genuine queued waiter is a live holder's lock loser, and it was
                // already excluded from this sweep by backupIsWaitingForVolumeLock).
                // The lock is keyed on the volume for a Docker-volume job and on the
                // job for a host-path job, so the check must match the run's source.
                $release = $wasRunning
                    || ! $this->lockHeldByAnotherActiveBackup($run, $volume);

                if ($release) {
                    $this->releaseLock($lockKey);
                }
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

        // Close backup group runs whose worker crashed. Only once no member run is
        // still active (a live member is reconciled on its own liveness first, and
        // the group is closed on a later sweep). markFailed emits the single
        // aggregated failure notification for the whole group.
        $groupCount = 0;
        $this->staleGroupRuns($cutoff)->each(function (BackupGroupRun $run) use ($runBackupGroup, $reason, &$groupCount): void {
            // A crashed group run can leave its shared WithoutOverlapping lock
            // (24h TTL) behind — including a still-"queued" run, because the job
            // acquires the group lock before RunBackupGroup flips the run to
            // running. Force-release it whenever we actually close a stale run so
            // the group is not blocked for a day; force-release is a harmless no-op
            // when no lock is held (a queued run the worker never picked up), and
            // group-run creation is serialized so no other run holds this lock.
            if ($runBackupGroup->markFailed($run, new RuntimeException($reason))) {
                $this->releaseLock('backup-group-'.$run->backup_job_group_id);
                $groupCount++;
            }
        });

        // Runs the sweep just failed (or runs whose worker died during restart)
        // may still have application containers stopped. Restart them now.
        $restartedCount = 0;
        $this->backupRunsWithStoppedContainers($cutoff)->each(function (BackupRun $run) use ($runBackup, &$restartedCount): void {
            try {
                // Never restart a container that an active sibling member of the
                // same live group run has deliberately stopped for its own backup:
                // that would bring the application up mid-archive and corrupt it.
                // Only the containers no active member still needs stopped are
                // recovered here.
                if ($runBackup->restartStoppedContainers($run, $this->containersHeldByActiveGroupMembers($run))) {
                    $restartedCount++;
                }
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

        $this->info("Reconciled {$backupCount} stale backup run(s), {$restoreCount} stale restore run(s) and {$groupCount} stale backup group run(s); restarted containers for {$restartedCount} interrupted run(s).");

        return self::SUCCESS;
    }

    /** @return Collection<int, BackupGroupRun> */
    private function staleGroupRuns(CarbonInterface $cutoff): Collection
    {
        return BackupGroupRun::query()
            ->whereIn('status', [BackupGroupRun::STATUS_QUEUED, BackupGroupRun::STATUS_RUNNING])
            ->where(fn ($query) => $this->candidateConstraint($query, $cutoff, BackupGroupRun::STATUS_RUNNING))
            ->get()
            ->filter(fn (BackupGroupRun $run) => $this->groupRunIsStale($run, $cutoff)
                && ! $this->groupRunHasActiveMemberRun($run, $cutoff));
    }

    /**
     * A group run drives its members sequentially and updates last_heartbeat_at
     * after each one, so a running group with a stale heartbeat and no in-flight
     * member is a crashed worker. A queued group past the age gate was never
     * picked up. It has no Docker container of its own — its liveness is the
     * member runs, checked separately.
     */
    private function groupRunIsStale(BackupGroupRun $run, CarbonInterface $cutoff): bool
    {
        if ($run->status === BackupGroupRun::STATUS_RUNNING) {
            $progressedAt = $run->last_heartbeat_at ?? $run->started_at ?? $run->created_at;

            return $progressedAt !== null && $progressedAt->lessThan($cutoff);
        }

        return $run->created_at !== null && $run->created_at->lessThan($cutoff);
    }

    /**
     * Whether a member run of this group is still queued or running. A live member
     * is reconciled on its own container liveness first; the group is only closed
     * once every member has reached a terminal state, so its aggregated outcome is
     * not declared while a member is still working.
     */
    private function groupRunHasActiveMemberRun(BackupGroupRun $run, CarbonInterface $cutoff): bool
    {
        return BackupRun::query()
            ->where('backup_group_run_id', $run->id)
            ->where(function ($query) use ($cutoff): void {
                $query
                    ->whereIn('status', [BackupRun::STATUS_QUEUED, BackupRun::STATUS_RUNNING])
                    // A member that finished after the cutoff means the group
                    // worker was active recently: it runs members synchronously and
                    // only refreshes the group heartbeat between them, so it may be
                    // finalizing that member (e.g. recording archive metadata) with
                    // a lagging heartbeat. Treat the group as still progressing so a
                    // long member does not get its live group run reconciled.
                    ->orWhere(fn ($q) => $q->whereNotNull('finished_at')->where('finished_at', '>=', $cutoff));
            })
            ->exists();
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

        // A host-path job has no volume: it serializes on its per-job lock, so a
        // queued run of the same job legitimately waiting on that lock must be
        // exempt just like a volume waiter — otherwise WithoutOverlapping requeuing
        // it (releaseAfter) would look stale and get failed out from under itself.
        return $this->lockHeldByAnotherActiveBackup($run, $run->job?->volume_name);
    }

    /**
     * Whether the lock this backup run serializes on is held — or was only just
     * released — by another active run: the volume lock for a Docker-volume job,
     * the per-job lock for a host-path job (which restores never share).
     */
    private function lockHeldByAnotherActiveBackup(BackupRun $run, ?string $volume): bool
    {
        if (filled($volume)) {
            return $this->volumeHeldByAnotherActiveRun($volume, backupRunId: $run->id);
        }

        $recentlyReleased = now()->subSeconds(120);

        return BackupRun::query()
            ->where('backup_job_id', $run->backup_job_id)
            ->whereKeyNot($run->id)
            // A pre-restore safety backup runs inline and never holds this lock.
            ->where('trigger', '!=', BackupRun::TRIGGER_PRE_RESTORE)
            ->where(fn ($query) => $this->stillHoldsVolume($query, $recentlyReleased))
            ->exists();
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
     * just released (within the requeue window), terminal but still finalizing with
     * a fresh heartbeat, or terminal but still owning stopped containers their
     * finally has not restarted yet.
     *
     * The heartbeat case matters because the WithoutOverlapping lock is held for the
     * whole job — the run flips terminal before archive-metadata listing and
     * notifications, which can outlast the fixed requeue window. The holder refreshes
     * its heartbeat across that finalization, so it keeps counting as a holder and a
     * legitimately-waiting run is not swept. The stopped-containers case keeps this
     * consistent with the jobs' volumeBusy guard.
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
                ->where(fn ($inner) => $inner
                    ->where('finished_at', '>=', $recentlyReleased)
                    ->orWhere('last_heartbeat_at', '>=', $recentlyReleased)))
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
    private function backupRunsWithStoppedContainers(CarbonInterface $cutoff): Collection
    {
        return BackupRun::query()
            ->whereIn('status', [BackupRun::STATUS_SUCCESS, BackupRun::STATUS_FAILED, BackupRun::STATUS_CANCELLED])
            ->whereNotNull('stopped_container_ids')
            ->where('stopped_container_ids', '!=', '[]')
            ->where(function ($query) use ($cutoff): void {
                // Recover the containers unless we would be racing the group worker
                // that is *currently* restarting this member's containers in its
                // finally (docker start is idempotent, but a transient failure
                // there could clash with the live restart).
                $query
                    // Not the current member of a live group run: either it has no
                    // live group run at all...
                    ->whereDoesntHave('groupRun', fn ($group) => $group
                        ->where('status', BackupGroupRun::STATUS_RUNNING)
                        ->where('last_heartbeat_at', '>=', $cutoff))
                    // ...or the worker has already moved on to a later member (a
                    // higher-id member run exists), so this member's restart has
                    // finished or failed and must be recovered now — not left down
                    // until the whole group ends.
                    ->orWhereExists(fn ($exists) => $exists
                        ->selectRaw('1')
                        ->from('backup_runs as later_member')
                        ->whereColumn('later_member.backup_group_run_id', 'backup_runs.backup_group_run_id')
                        ->whereColumn('later_member.id', '>', 'backup_runs.id'));
            })
            ->get();
    }

    /**
     * Container ids that a still-running sibling member of this run's group run
     * has deliberately stopped for its own in-flight backup. These must not be
     * restarted while recovering this run's leftover containers — doing so would
     * bring the application up in the middle of the sibling's archive. Empty for a
     * standalone run or one whose group run is no longer running.
     *
     * @return array<int, string>
     */
    private function containersHeldByActiveGroupMembers(BackupRun $run): array
    {
        if ($run->backup_group_run_id === null) {
            return [];
        }

        return BackupRun::query()
            ->where('backup_group_run_id', $run->backup_group_run_id)
            ->whereKeyNot($run->id)
            ->where('status', BackupRun::STATUS_RUNNING)
            ->whereNotNull('stopped_container_ids')
            ->pluck('stopped_container_ids')
            ->flatMap(fn ($ids) => is_array($ids) ? $ids : [])
            ->filter()
            ->unique()
            ->values()
            ->all();
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
