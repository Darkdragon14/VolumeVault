<?php

namespace App\Jobs;

use App\Actions\Backup\RunBackup;
use App\Models\BackupRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class RunBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct(public readonly int $backupRunId) {}

    public function middleware(): array
    {
        $run = BackupRun::find($this->backupRunId);

        return [(new WithoutOverlapping('backup-job-'.($run?->backup_job_id ?? $this->backupRunId)))->expireAfter(86400)];
    }

    public function handle(RunBackup $runBackup): void
    {
        $run = BackupRun::findOrFail($this->backupRunId);

        $runBackup->handle($run);
    }
}
