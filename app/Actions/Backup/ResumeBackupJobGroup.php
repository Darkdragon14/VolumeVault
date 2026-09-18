<?php

namespace App\Actions\Backup;

use App\Models\BackupJobGroup;
use App\Services\Docker\LocalDockerExecution;
use App\Services\Scheduling\BackupScheduleCalculator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ResumeBackupJobGroup
{
    public function __construct(
        private readonly BackupScheduleCalculator $scheduleCalculator,
        private readonly WithBackupGroupMutationLocks $withGroupLocks,
    ) {}

    public function handle(BackupJobGroup $group): BackupJobGroup
    {
        LocalDockerExecution::validate();

        return $this->withGroupLocks->handle([$group->id], function (Collection $groups) use ($group): BackupJobGroup {
            $lockedGroup = $groups->get($group->id);

            if (! $lockedGroup instanceof BackupJobGroup) {
                throw (new ModelNotFoundException)->setModel(BackupJobGroup::class, [$group->id]);
            }

            if (! in_array($lockedGroup->status, [BackupJobGroup::STATUS_PAUSED, BackupJobGroup::STATUS_ERROR], true)) {
                throw ValidationException::withMessages([
                    'group' => 'Only paused or errored groups can be resumed.',
                ]);
            }

            $lockedGroup->forceFill([
                'status' => BackupJobGroup::STATUS_ACTIVE,
                'pause_reason' => null,
                'last_error' => null,
                'last_error_at' => null,
                'next_run_at' => $this->scheduleCalculator->nextRunAt(
                    $lockedGroup->schedule_type,
                    $lockedGroup->schedule_config ?? [],
                    null,
                    $lockedGroup->timezone,
                ),
            ])->save();

            return $lockedGroup;
        });
    }
}
