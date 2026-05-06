<?php

namespace App\Actions\Backup;

use App\Models\ActivityLog;
use App\Models\BackupJob;

class MarkMissingVolumeJobs
{
    public function handle(array $missingVolumeNames): int
    {
        $names = collect($missingVolumeNames)->filter()->unique()->values();

        if ($names->isEmpty()) {
            return 0;
        }

        $affected = 0;

        BackupJob::query()
            ->whereIn('volume_name', $names->all())
            ->where('status', '!=', BackupJob::STATUS_RUNNING)
            ->get()
            ->each(function (BackupJob $job) use (&$affected): void {
                $message = 'Docker volume not found: '.$job->volume_name;

                $job->forceFill([
                    'status' => BackupJob::STATUS_ERROR,
                    'last_error' => $message,
                    'pause_reason' => $message,
                ])->save();

                ActivityLog::record('missing_volume_detected', $message, $job, [
                    'volume_name' => $job->volume_name,
                ]);

                $affected++;
            });

        return $affected;
    }
}
