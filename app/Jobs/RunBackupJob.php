<?php

namespace App\Jobs;

use App\Actions\Backup\RunBackup;
use App\Models\BackupRun;
use App\Models\RestoreRun;
use App\Support\VolumeJobLock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

class RunBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;

    public function __construct(public readonly int $backupRunId) {}

    /**
     * Time-based retry budget instead of a fixed try count: a backup that loses
     * the volume lock to a concurrent same-volume operation is released back to
     * the queue, which would consume a fixed attempt and fail it after one
     * release. A deadline lets it keep waiting until the lock frees. Genuine
     * backup failures never retry — RunBackup catches them and marks the run
     * failed without throwing. Aligned with the lock's expireAfter.
     */
    public function retryUntil(): Carbon
    {
        return now()->addDay();
    }

    public function middleware(): array
    {
        // Serialize on the Docker volume so a backup cannot run while an in-place
        // restore is mid-wipe on the same volume (and vice versa). Host-path jobs
        // have no volume and keep their previous per-job key.
        $run = BackupRun::with('job:id,volume_name')->find($this->backupRunId);
        $key = VolumeJobLock::key($run?->job?->volume_name, 'backup-job-'.($run?->backup_job_id ?? $this->backupRunId));

        // shared() drops the per-job-class namespace from the lock key so this
        // backup and a RunRestoreJob keyed on the same volume contend for the one
        // lock instead of two class-scoped locks that never collide. releaseAfter
        // requeues a lock loser after a delay so it waits and serializes (under
        // the retryUntil budget) rather than failing.
        return [(new WithoutOverlapping($key))->shared()->releaseAfter(60)->expireAfter(86400)];
    }

    public function handle(RunBackup $runBackup): void
    {
        $run = BackupRun::findOrFail($this->backupRunId);

        // Defense against an expired WithoutOverlapping lock (24h TTL, no job
        // timeout): if another run is already executing on this backup's volume,
        // the lock failed to serialize us — requeue rather than overlap. The
        // inline pre-restore safety backup runs via RunBackup directly (not this
        // job), so it never trips this guard.
        if ($this->volumeBusy($run)) {
            $this->release(60);

            return;
        }

        $runBackup->handle($run);
    }

    /**
     * Whether another backup or restore is still working on this backup's Docker
     * volume. "Working" is not only status=running: a run flips to a terminal
     * status before its finally block restarts the containers it stopped, so a run
     * that still has stopped_container_ids is counted too — otherwise a waiter that
     * started after the 24h lock expired could read/clear the volume while the
     * previous run is mid-restart of containers mounting it.
     */
    private function volumeBusy(BackupRun $run): bool
    {
        $run->loadMissing('job');
        $volume = $run->job?->volume_name;

        if (! filled($volume)) {
            // Host-path jobs have no volume and serialize on their per-job lock; if
            // that lock expired (24h TTL), another still-working run of the same job
            // means we must requeue rather than overlap it. Restores never share
            // this lock, so only sibling backup runs count.
            return BackupRun::query()
                ->where('backup_job_id', $run->backup_job_id)
                ->whereKeyNot($run->getKey())
                ->where(fn ($query) => $this->stillWorking($query, includeBackupCleanup: true))
                ->exists();
        }

        $backupActive = BackupRun::query()
            ->whereHas('job', fn ($query) => $query->where('volume_name', $volume))
            ->whereKeyNot($run->getKey())
            ->where(fn ($query) => $this->stillWorking($query, includeBackupCleanup: true))
            ->exists();

        $restoreActive = RestoreRun::query()
            ->where('target_volume_name', $volume)
            ->where(fn ($query) => $this->stillWorking($query))
            ->exists();

        return $backupActive || $restoreActive;
    }

    /**
     * Constrain to runs that are running, terminal but still owning stopped
     * containers, or backup runs whose credential-bearing helper still needs cleanup.
     */
    private function stillWorking($query, bool $includeBackupCleanup = false): void
    {
        $query
            ->where('status', BackupRun::STATUS_RUNNING)
            ->orWhere(fn ($q) => $q->whereNotNull('stopped_container_ids')->where('stopped_container_ids', '!=', '[]'));

        if ($includeBackupCleanup) {
            $query->orWhere('docker_container_cleanup_pending', true);
        }
    }

    /**
     * Called by the queue when the job fails outright (timeout, queue:restart,
     * uncaught exception). Ensures the run never stays stuck in running/queued.
     */
    public function failed(Throwable $exception): void
    {
        $run = BackupRun::find($this->backupRunId);

        // Only fail a run the queue never actually started. A RUNNING run is owned
        // by its worker (timeout 0, so a long backup is legitimate); a copy
        // redelivered until retryUntil must not fail it — nor let its container
        // restart race the live worker — out from under it. A genuinely dead
        // RUNNING run is closed by stale-run reconciliation. Mirrors RunBackupGroupJob.
        if ($run && $run->status === BackupRun::STATUS_QUEUED) {
            app(RunBackup::class)->markFailed($run, $exception);
        }
    }
}
