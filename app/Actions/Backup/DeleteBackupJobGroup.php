<?php

namespace App\Actions\Backup;

use App\Models\BackupGroupRun;
use App\Models\BackupJobGroup;
use Illuminate\Validation\ValidationException;

class DeleteBackupJobGroup
{
    public function __construct(private readonly WithBackupGroupMutationLocks $withGroupLocks) {}

    public function handle(BackupJobGroup $group): void
    {
        $this->withGroupLocks->handle([$group->id], function ($groups) use ($group): void {
            $lockedGroup = $groups->get($group->id);

            if ($lockedGroup === null) {
                return;
            }

            if ($lockedGroup->members()->exists()) {
                throw ValidationException::withMessages([
                    'group' => 'Remove or reassign this group\'s jobs before deleting it.',
                ]);
            }

            if ($lockedGroup->groupRuns()->whereIn('status', [BackupGroupRun::STATUS_QUEUED, BackupGroupRun::STATUS_RUNNING])->exists()) {
                throw ValidationException::withMessages([
                    'group' => 'This group has a backup run in progress. Wait for it to finish before deleting it.',
                ]);
            }

            if ($lockedGroup->hasOutstandingFinalizations()) {
                throw ValidationException::withMessages([
                    'group' => 'This group still has notification finalization work pending. Wait for it to finish before deleting it.',
                ]);
            }

            $lockedGroup->delete();
        });
    }
}
