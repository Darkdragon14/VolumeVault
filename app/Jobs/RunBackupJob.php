<?php

namespace App\Jobs;

use App\Actions\Backup\RunBackup;
use App\Models\BackupRun;
use App\Support\VolumeJobLock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RunBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct(public readonly int $backupRunId) {}

    public function middleware(): array
    {
        // Serialize on the Docker volume so a backup cannot run while an in-place
        // restore is mid-wipe on the same volume (and vice versa). Host-path jobs
        // have no volume and keep their previous per-job key.
        $run = BackupRun::with('job:id,volume_name')->find($this->backupRunId);
        $key = VolumeJobLock::key($run?->job?->volume_name, 'backup-job-'.($run?->backup_job_id ?? $this->backupRunId));

        // shared() drops the per-job-class namespace from the lock key so this
        // backup and a RunRestoreJob keyed on the same volume contend for the one
        // lock instead of two class-scoped locks that never collide.
        return [(new WithoutOverlapping($key))->shared()->expireAfter(86400)];
    }

    public function handle(RunBackup $runBackup): void
    {
        $run = BackupRun::findOrFail($this->backupRunId);

        $runBackup->handle($run);
    }

    /**
     * Called by the queue when the job fails outright (timeout, queue:restart,
     * uncaught exception). Ensures the run never stays stuck in running/queued.
     */
    public function failed(Throwable $exception): void
    {
        $run = BackupRun::find($this->backupRunId);

        if ($run) {
            app(RunBackup::class)->markFailed($run, $exception);
        }
    }
}
