<?php

namespace App\Actions\Backup;

use App\Models\ActivityLog;
use App\Models\BackupGroupRun;
use App\Models\BackupJobGroup;
use App\Models\User;
use App\Services\Scheduling\BackupScheduleCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Queue one aggregated run for a backup group and advance the group's schedule.
 *
 * Mirrors {@see CreateBackupRun}: the group — not its members — owns next_run_at,
 * so this action advances it once when the run is queued (anchored on the slot it
 * is about to service) and {@see RunBackupGroup} never recomputes it.
 */
class CreateBackupGroupRun
{
    public function __construct(private readonly BackupScheduleCalculator $scheduleCalculator) {}

    public function handle(BackupJobGroup $group, string $trigger, ?User $initiatedBy = null): BackupGroupRun
    {
        if ($group->status !== BackupJobGroup::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'group' => 'Only active backup groups can run.',
            ]);
        }

        if ($group->activeMembers()->count() === 0) {
            throw ValidationException::withMessages([
                'group' => 'This backup group has no active member jobs to run.',
            ]);
        }

        $alreadyRunning = BackupGroupRun::query()
            ->where('backup_job_group_id', $group->id)
            ->whereIn('status', [BackupGroupRun::STATUS_QUEUED, BackupGroupRun::STATUS_RUNNING])
            ->exists();

        if ($alreadyRunning) {
            throw ValidationException::withMessages([
                'group' => 'A run is already queued or running for this backup group.',
            ]);
        }

        return DB::transaction(function () use ($group, $trigger, $initiatedBy): BackupGroupRun {
            $run = BackupGroupRun::create([
                'backup_job_group_id' => $group->id,
                'initiated_by_user_id' => $initiatedBy?->getKey(),
                'status' => BackupGroupRun::STATUS_QUEUED,
                'trigger' => $trigger,
            ]);

            // Anchor the next slot on the theoretical occurrence we are about to
            // service (see CreateBackupRun): keeps the schedule on its grid and
            // prevents drift when the worker dispatches late.
            $anchor = $group->next_run_at;

            $group->forceFill([
                'next_run_at' => $this->scheduleCalculator->nextRunAt(
                    $group->schedule_type,
                    $group->schedule_config ?? [],
                    $anchor && $anchor->isPast() ? $anchor : null,
                    $group->timezone,
                ),
                'last_error' => null,
                'last_error_at' => null,
            ])->save();

            ActivityLog::record('backup_group_run_queued', 'Backup group run queued.', $run, [
                'backup_job_group_id' => $group->id,
                'trigger' => $trigger,
            ]);

            return $run;
        });
    }
}
