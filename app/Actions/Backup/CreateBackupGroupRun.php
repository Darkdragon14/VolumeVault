<?php

namespace App\Actions\Backup;

use App\Models\ActivityLog;
use App\Models\BackupGroupRun;
use App\Models\BackupJobGroup;
use App\Models\User;
use App\Services\Agents\HostWorkAdmission;
use App\Services\Docker\LocalDockerExecution;
use App\Services\Scheduling\BackupScheduleCalculator;
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
    public function __construct(
        private readonly BackupScheduleCalculator $scheduleCalculator,
        private readonly WithBackupGroupMutationLocks $withGroupLocks,
    ) {}

    public function handle(BackupJobGroup $group, string $trigger, ?User $initiatedBy = null): ?BackupGroupRun
    {
        LocalDockerExecution::validate();

        return $this->withGroupLocks->handle([$group->id], function ($groups) use ($group, $trigger, $initiatedBy): ?BackupGroupRun {
            $lockedGroup = $groups->get($group->id);

            if ($lockedGroup === null || $lockedGroup->status !== BackupJobGroup::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'group' => 'Only active backup groups can run.',
                ]);
            }

            $alreadyRunning = BackupGroupRun::query()
                ->where('backup_job_group_id', $lockedGroup->id)
                ->whereIn('status', [BackupGroupRun::STATUS_QUEUED, BackupGroupRun::STATUS_RUNNING])
                ->exists();

            if ($alreadyRunning) {
                if ($trigger === BackupGroupRun::TRIGGER_SCHEDULED) {
                    return null;
                }

                throw ValidationException::withMessages([
                    'group' => 'A run is already queued or running for this backup group.',
                ]);
            }

            if ($lockedGroup->runnableMembers()->doesntExist()) {
                if ($trigger === BackupGroupRun::TRIGGER_SCHEDULED) {
                    $this->advanceSkippedSchedule($lockedGroup);

                    return null;
                }

                throw ValidationException::withMessages([
                    'group' => 'This backup group has no runnable member jobs (all are paused or it has none).',
                ]);
            }

            return $this->createRun($lockedGroup, $trigger, $initiatedBy);
        });
    }

    private function createRun(BackupJobGroup $group, string $trigger, ?User $initiatedBy): BackupGroupRun
    {
        app(HostWorkAdmission::class)->assertGroupAccepting($group);
        $run = BackupGroupRun::create([
            'backup_job_group_id' => $group->id,
            'initiated_by_user_id' => $initiatedBy?->getKey(),
            'status' => BackupGroupRun::STATUS_QUEUED,
            'trigger' => $trigger,
            'scheduled_for' => $trigger === BackupGroupRun::TRIGGER_SCHEDULED ? $group->next_run_at : null,
        ]);

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
    }

    private function advanceSkippedSchedule(BackupJobGroup $group): void
    {
        $anchor = $group->next_run_at;

        $group->forceFill([
            'next_run_at' => $this->scheduleCalculator->nextRunAt(
                $group->schedule_type,
                $group->schedule_config ?? [],
                $anchor && $anchor->isPast() ? $anchor : null,
                $group->timezone,
            ),
            'last_error' => 'Skipped: the group has no runnable member jobs.',
            'last_error_at' => now(),
        ])->save();

        ActivityLog::record('backup_group_run_skipped', 'Backup group run skipped: no runnable member jobs.', $group);
    }
}
