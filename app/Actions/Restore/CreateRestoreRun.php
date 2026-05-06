<?php

namespace App\Actions\Restore;

use App\Models\ActivityLog;
use App\Models\BackupJob;
use App\Models\RestoreRun;
use Illuminate\Validation\ValidationException;

class CreateRestoreRun
{
    public function __construct(private readonly GenerateRestoreVolumeName $generateRestoreVolumeName) {}

    public function handle(BackupJob $job, array $data): RestoreRun
    {
        $job->loadMissing('destination');
        $mode = $data['mode'] ?? RestoreRun::MODE_NEW_VOLUME;

        if ($mode !== RestoreRun::MODE_NEW_VOLUME) {
            throw ValidationException::withMessages([
                'mode' => 'In-place restore modes are structured for later, but currently disabled.',
            ]);
        }

        $targetVolume = $data['target_volume_name'] ?: $this->generateRestoreVolumeName->handle($job->volume_name);

        if ($targetVolume === $job->volume_name) {
            throw ValidationException::withMessages([
                'target_volume_name' => 'Restore-to-new-volume cannot use the source volume name.',
            ]);
        }

        $run = RestoreRun::create([
            'backup_job_id' => $job->id,
            'backup_destination_id' => $job->backup_destination_id,
            'selected_backup_key' => $data['selected_backup_key'],
            'source_volume_name' => $job->volume_name,
            'target_volume_name' => $targetVolume,
            'mode' => RestoreRun::MODE_NEW_VOLUME,
            'status' => RestoreRun::STATUS_QUEUED,
            'confirmation_text' => $data['confirmation_text'] ?? null,
        ]);

        ActivityLog::record('restore_run_started', 'Restore run queued.', $run, [
            'backup_job_id' => $job->id,
            'target_volume_name' => $targetVolume,
        ]);

        return $run;
    }
}
