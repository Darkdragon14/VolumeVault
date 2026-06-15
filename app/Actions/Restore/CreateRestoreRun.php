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
        $sourceName = $job->sourceName();

        $targetVolume = $this->isInPlace($mode)
            ? $this->resolveInPlaceTarget($job)
            : $this->resolveNewVolumeTarget($job, $sourceName, $data['target_volume_name'] ?? null);

        $run = RestoreRun::create([
            'backup_job_id' => $job->id,
            'backup_destination_id' => $job->backup_destination_id,
            'selected_backup_key' => $data['selected_backup_key'],
            'source_volume_name' => $sourceName,
            'target_volume_name' => $targetVolume,
            'mode' => $mode,
            'status' => RestoreRun::STATUS_QUEUED,
            'confirmation_text' => $data['confirmation_text'] ?? null,
        ]);

        ActivityLog::record('restore_run_started', 'Restore run queued.', $run, [
            'backup_job_id' => $job->id,
            'target_volume_name' => $targetVolume,
        ]);

        return $run;
    }

    private function isInPlace(string $mode): bool
    {
        return in_array($mode, [RestoreRun::MODE_INPLACE, RestoreRun::MODE_SAFE_INPLACE], true);
    }

    /**
     * In-place modes overwrite the source volume itself. They only make sense
     * for Docker-volume sources and require the typed confirmation to match the
     * target (= source) volume name. Validated again here as defense in depth —
     * StoreRestoreRequest is the first gate, but CreateRestoreRun is also reached
     * by tests and any future programmatic caller.
     */
    private function resolveInPlaceTarget(BackupJob $job): string
    {
        if (! $job->isDockerVolumeSource()) {
            throw ValidationException::withMessages([
                'mode' => 'In-place restore is only available for Docker volume sources.',
            ]);
        }

        return (string) $job->volume_name;
    }

    private function resolveNewVolumeTarget(BackupJob $job, string $sourceName, ?string $requested): string
    {
        $targetVolume = ($requested ?: null) ?: $this->generateRestoreVolumeName->handle($sourceName);

        if ($job->isDockerVolumeSource() && $targetVolume === $job->volume_name) {
            throw ValidationException::withMessages([
                'target_volume_name' => 'Restore-to-new-volume cannot use the source volume name.',
            ]);
        }

        return $targetVolume;
    }
}
