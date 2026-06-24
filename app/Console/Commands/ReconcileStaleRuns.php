<?php

namespace App\Console\Commands;

use App\Actions\Backup\RunBackup;
use App\Actions\Docker\ContainerIsAlive;
use App\Actions\Restore\RunRestore;
use App\Models\BackupRun;
use App\Models\RestoreRun;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
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
            $runBackup->markFailed($run, new RuntimeException($reason));
            $backupCount++;
        });

        $restoreCount = 0;
        $this->staleRestoreRuns($cutoff)->each(function (RestoreRun $run) use ($runRestore, $reason, &$restoreCount): void {
            $runRestore->markFailed($run, new RuntimeException($reason));
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
            ->get()
            ->filter(fn (BackupRun $run) => $this->isStale($run, $cutoff, BackupRun::STATUS_RUNNING));
    }

    /** @return Collection<int, RestoreRun> */
    private function staleRestoreRuns(CarbonInterface $cutoff): Collection
    {
        return RestoreRun::query()
            ->whereIn('status', [RestoreRun::STATUS_QUEUED, RestoreRun::STATUS_RUNNING])
            ->where(fn ($query) => $this->candidateConstraint($query, $cutoff, RestoreRun::STATUS_RUNNING))
            ->get()
            ->filter(fn (RestoreRun $run) => $this->isStale($run, $cutoff, RestoreRun::STATUS_RUNNING)
                && ! $this->restoreIsProgressing($run, $cutoff));
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
        // leaves it cold. Covers a long download that has no container to check yet.
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
