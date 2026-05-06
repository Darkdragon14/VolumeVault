<?php

namespace App\Actions\Backup;

use App\Models\ActivityLog;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerVolume;
use App\Services\Scheduling\BackupScheduleCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateBackupRun
{
    public function __construct(private readonly BackupScheduleCalculator $scheduleCalculator) {}

    public function handle(BackupJob $job, string $trigger): BackupRun
    {
        $job->loadMissing('destination');

        if ($job->status !== BackupJob::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'job' => 'Only active backup jobs can run.',
            ]);
        }

        if (! $job->destination?->is_active) {
            throw ValidationException::withMessages([
                'destination' => 'The backup destination is inactive.',
            ]);
        }

        $volume = DockerVolume::where('name', $job->volume_name)->first();

        if (! $volume || ! $volume->exists) {
            throw ValidationException::withMessages([
                'volume' => 'Docker volume not found: '.$job->volume_name,
            ]);
        }

        $alreadyRunning = BackupRun::query()
            ->where('backup_job_id', $job->id)
            ->whereIn('status', [BackupRun::STATUS_QUEUED, BackupRun::STATUS_RUNNING])
            ->exists();

        if ($alreadyRunning) {
            throw ValidationException::withMessages([
                'job' => 'A backup run is already queued or running for this job.',
            ]);
        }

        return DB::transaction(function () use ($job, $trigger): BackupRun {
            $run = BackupRun::create([
                'backup_job_id' => $job->id,
                'status' => BackupRun::STATUS_QUEUED,
                'trigger' => $trigger,
            ]);

            $job->forceFill([
                'next_run_at' => $this->scheduleCalculator->nextRunAt($job->schedule_type, $job->schedule_config ?? []),
                'last_error' => null,
            ])->save();

            ActivityLog::record('backup_run_queued', 'Backup run queued.', $run, [
                'backup_job_id' => $job->id,
                'trigger' => $trigger,
            ]);

            return $run;
        });
    }
}
