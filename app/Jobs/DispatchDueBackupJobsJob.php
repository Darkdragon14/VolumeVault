<?php

namespace App\Jobs;

use App\Actions\Backup\CreateBackupRun;
use App\Models\ActivityLog;
use App\Models\BackupJob;
use App\Models\BackupRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class DispatchDueBackupJobsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('dispatch-due-backup-jobs'))->expireAfter(300)];
    }

    public function handle(CreateBackupRun $createBackupRun): void
    {
        BackupJob::query()
            ->where('status', BackupJob::STATUS_ACTIVE)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->orderBy('next_run_at')
            ->get()
            ->each(function (BackupJob $job) use ($createBackupRun): void {
                $alreadyRunning = BackupRun::query()
                    ->where('backup_job_id', $job->id)
                    ->whereIn('status', [BackupRun::STATUS_QUEUED, BackupRun::STATUS_RUNNING])
                    ->exists();

                if ($alreadyRunning) {
                    return;
                }

                try {
                    $run = $createBackupRun->handle($job, BackupRun::TRIGGER_SCHEDULED);
                    RunBackupJob::dispatch($run->id);
                } catch (Throwable $exception) {
                    ActivityLog::record('backup_dispatch_failed', 'Failed to dispatch due backup job.', $job, [
                        'error' => str($exception->getMessage())->limit(500)->toString(),
                    ]);
                }
            });
    }
}
