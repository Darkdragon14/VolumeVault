<?php

namespace App\Actions\Backup;

use App\Models\ActivityLog;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerVolume;
use App\Models\User;
use App\Services\Scheduling\BackupScheduleCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateBackupRun
{
    public function __construct(private readonly BackupScheduleCalculator $scheduleCalculator) {}

    public function handle(BackupJob $job, string $trigger, ?User $initiatedBy = null): BackupRun
    {
        $job->loadMissing('destination');

        // A group member is only ever run as part of its group run (which creates
        // its BackupRun directly). Block the standalone run paths — manual "run
        // now" (web/API) and stack backups all funnel through here.
        if ($job->isGroupMember()) {
            throw ValidationException::withMessages([
                'job' => 'This job belongs to a backup group; run the group instead.',
            ]);
        }

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

        if ($job->isDockerVolumeSource()) {
            $volume = DockerVolume::where('name', $job->volume_name)->first();

            if (! $volume || ! $volume->exists) {
                throw ValidationException::withMessages([
                    'volume' => 'Docker volume not found: '.$job->volume_name,
                ]);
            }
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

        return DB::transaction(function () use ($job, $trigger, $initiatedBy): BackupRun {
            $run = BackupRun::create([
                'backup_job_id' => $job->id,
                'initiated_by_user_id' => $initiatedBy?->getKey(),
                'status' => BackupRun::STATUS_QUEUED,
                'trigger' => $trigger,
            ]);

            // Anchor the next slot on the theoretical occurrence we are about to
            // service (the current next_run_at when it is already due) rather than
            // on "now". This keeps the schedule on its grid and prevents drift when
            // the worker dispatches late. CreateBackupRun owns next_run_at for the
            // whole run lifecycle; RunBackup no longer recomputes it on success.
            $anchor = $job->next_run_at;

            $job->forceFill([
                'next_run_at' => $this->scheduleCalculator->nextRunAt(
                    $job->schedule_type,
                    $job->schedule_config ?? [],
                    $anchor && $anchor->isPast() ? $anchor : null,
                    $job->timezone,
                ),
                'last_error' => null,
                'last_error_at' => null,
            ])->save();

            ActivityLog::record('backup_run_queued', 'Backup run queued.', $run, [
                'backup_job_id' => $job->id,
                'trigger' => $trigger,
            ]);

            return $run;
        });
    }
}
