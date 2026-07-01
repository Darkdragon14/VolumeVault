<?php

namespace App\Jobs;

use App\Actions\Backup\RunBackupGroup;
use App\Models\BackupGroupRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

class RunBackupGroupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;

    public function __construct(public readonly int $backupGroupRunId) {}

    /**
     * A group run drives its members sequentially and each may wait on its
     * volume lock, so use a time-based budget rather than a fixed try count.
     * RunBackupGroup catches member failures and never throws for them, so this
     * only affects genuine infrastructure faults. Aligned with the member locks.
     */
    public function retryUntil(): Carbon
    {
        return now()->addDay();
    }

    public function middleware(): array
    {
        // Serialize on the group (not the individual run) so two runs of the same
        // group — e.g. a manual run racing a scheduled one — can never fan out to
        // the same member volumes concurrently.
        $run = BackupGroupRun::find($this->backupGroupRunId);
        $key = 'backup-group-'.($run?->backup_job_group_id ?? $this->backupGroupRunId);

        return [(new WithoutOverlapping($key))->expireAfter(86400)];
    }

    public function handle(RunBackupGroup $runBackupGroup): void
    {
        $groupRun = BackupGroupRun::findOrFail($this->backupGroupRunId);

        $runBackupGroup->handle($groupRun);
    }

    /**
     * Called by the queue when the job fails outright (timeout, queue:restart,
     * uncaught exception). Ensures the group run never stays stuck running/queued
     * and that the single aggregated failure notification still fires.
     */
    public function failed(Throwable $exception): void
    {
        $groupRun = BackupGroupRun::find($this->backupGroupRunId);

        if ($groupRun) {
            app(RunBackupGroup::class)->markFailed($groupRun, $exception);
        }
    }
}
