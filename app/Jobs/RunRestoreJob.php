<?php

namespace App\Jobs;

use App\Actions\Restore\RunRestore;
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

class RunRestoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;

    public function __construct(public readonly int $restoreRunId) {}

    /**
     * Time-based retry budget instead of a fixed try count: when this restore
     * loses the volume lock to another job, WithoutOverlapping releases it back
     * to the queue, which would consume a fixed attempt and fail it after one
     * release. A deadline lets it keep waiting until the holder finishes. Genuine
     * restore failures never retry regardless — RunRestore catches them and marks
     * the run failed without throwing, so the job itself completes. Aligned with
     * the lock's expireAfter so it cannot outlive the lock.
     */
    public function retryUntil(): Carbon
    {
        return now()->addDay();
    }

    public function middleware(): array
    {
        // Serialize on the target volume so a same-volume backup or another
        // destructive restore cannot run concurrently. target_volume_name is the
        // source volume for in-place modes and the fresh volume for new-volume
        // restores (the latter has no real contention but keys consistently).
        // shared() drops the per-job-class namespace from the lock key so this
        // restore and a RunBackupJob keyed on the same volume contend for the one
        // lock instead of two class-scoped locks that never collide. releaseAfter
        // requeues a lock loser after a delay so it waits and serializes (under
        // the retryUntil budget) rather than failing.
        $run = RestoreRun::find($this->restoreRunId);
        $key = VolumeJobLock::key($run?->target_volume_name, 'restore-run-'.$this->restoreRunId);

        return [(new WithoutOverlapping($key))->shared()->releaseAfter(60)->expireAfter(86400)];
    }

    public function handle(RunRestore $runRestore): void
    {
        $run = RestoreRun::findOrFail($this->restoreRunId);

        // Defense against an expired WithoutOverlapping lock: the lock has a 24h
        // TTL but a job has no timeout, so a legitimately long op can outlive its
        // lock and a waiter (still within retryUntil) could then acquire the
        // expired lock and start on the same volume. If another run is already
        // executing on this volume, the lock failed to serialize us — requeue
        // rather than overlap a possibly-destructive op.
        if ($this->volumeBusy($run)) {
            $this->release(60);

            return;
        }

        $runRestore->handle($run);
    }

    /**
     * Whether another backup or restore is still working on this restore's target
     * volume. "Working" is not only status=running: a run flips to a terminal
     * status before its finally block restarts the containers it stopped, so a run
     * that still has stopped_container_ids is counted too — otherwise a waiter that
     * started after the 24h lock expired could clear/extract the volume while the
     * previous run is mid-restart of containers mounting it. The inline pre-restore
     * safety backup is not a separate job and never reaches here.
     */
    private function volumeBusy(RestoreRun $run): bool
    {
        $volume = $run->target_volume_name;

        if (! filled($volume)) {
            return false;
        }

        $restoreActive = RestoreRun::query()
            ->where('target_volume_name', $volume)
            ->whereKeyNot($run->getKey())
            ->where(fn ($query) => $this->stillWorking($query))
            ->exists();

        $backupActive = BackupRun::query()
            ->whereHas('job', fn ($query) => $query->where('volume_name', $volume))
            ->where(fn ($query) => $this->stillWorking($query))
            ->exists();

        return $restoreActive || $backupActive;
    }

    /**
     * Constrain to runs that are running, or terminal but still owning stopped
     * containers their finally has not restarted yet.
     */
    private function stillWorking($query): void
    {
        $query
            ->where('status', RestoreRun::STATUS_RUNNING)
            ->orWhere(fn ($q) => $q->whereNotNull('stopped_container_ids')->where('stopped_container_ids', '!=', '[]'));
    }

    /**
     * Called by the queue when the job fails outright (timeout, queue:restart,
     * uncaught exception). Ensures the run never stays stuck in running/queued.
     */
    public function failed(Throwable $exception): void
    {
        $run = RestoreRun::find($this->restoreRunId);

        if ($run) {
            app(RunRestore::class)->markFailed($run, $exception);
        }
    }
}
