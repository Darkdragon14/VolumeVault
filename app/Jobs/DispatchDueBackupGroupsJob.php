<?php

namespace App\Jobs;

use App\Actions\Backup\CreateBackupGroupRun;
use App\Models\ActivityLog;
use App\Models\BackupGroupRun;
use App\Models\BackupJobGroup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Every minute, queue an aggregated run for each due backup group. Mirrors
 * {@see DispatchDueBackupJobsJob} but at the group level — the group owns the
 * schedule, so its members are excluded from the standalone dispatcher.
 */
class DispatchDueBackupGroupsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('dispatch-due-backup-groups'))->expireAfter(300)];
    }

    public function handle(CreateBackupGroupRun $createBackupGroupRun): void
    {
        BackupJobGroup::query()
            ->where('status', BackupJobGroup::STATUS_ACTIVE)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->orderBy('next_run_at')
            ->get()
            ->each(function (BackupJobGroup $group) use ($createBackupGroupRun): void {
                $alreadyRunning = BackupGroupRun::query()
                    ->where('backup_job_group_id', $group->id)
                    ->whereIn('status', [BackupGroupRun::STATUS_QUEUED, BackupGroupRun::STATUS_RUNNING])
                    ->exists();

                if ($alreadyRunning) {
                    return;
                }

                try {
                    $run = $createBackupGroupRun->handle($group, BackupGroupRun::TRIGGER_SCHEDULED);
                    RunBackupGroupJob::dispatch($run->id);
                } catch (Throwable $exception) {
                    ActivityLog::record('backup_group_dispatch_failed', 'Failed to dispatch due backup group.', $group, [
                        'error' => str($exception->getMessage())->limit(500)->toString(),
                    ]);
                }
            });
    }
}
