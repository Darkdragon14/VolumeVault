<?php

namespace App\Actions\Backup;

use App\Models\ActivityLog;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerVolume;
use App\Models\User;
use App\Services\Agents\AgentExecution;
use App\Services\Agents\HostWorkAdmission;
use App\Services\Scheduling\BackupScheduleCalculator;
use Illuminate\Validation\ValidationException;

class CreateBackupRun
{
    public function __construct(
        private readonly BackupScheduleCalculator $scheduleCalculator,
        private readonly WithDockerLabelMutationLocks $withLocks,
        private readonly ?CreateBackupRunRecord $createBackupRunRecord = null,
    ) {}

    /**
     * @param  array<int, string>|null  $allowedVolumeNames
     */
    public function handle(BackupJob $job, string $trigger, ?User $initiatedBy = null, ?array $allowedVolumeNames = null): BackupRun
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $current = BackupJob::query()->findOrFail($job->id);
            $references = $this->references($current);

            try {
                return $this->withLocks->handleForJobsOnHost(
                    $references['configuration_source'] === BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL ? [$job->id] : [],
                    [$references['destination_id']],
                    function ($destinations, $settings, $jobs) use ($job, $trigger, $initiatedBy, $references, $allowedVolumeNames): BackupRun {
                        $lockedJob = $references['configuration_source'] === BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL
                            ? $jobs->get($job->id)
                            : BackupJob::query()->lockForUpdate()->find($job->id);

                        if (! $lockedJob || $this->references($lockedJob) !== $references) {
                            throw new RetryDockerLabelMutation('Backup job references changed before they were locked.');
                        }

                        if ($allowedVolumeNames !== null && ! in_array($lockedJob->volume_name, $allowedVolumeNames, true)) {
                            throw ValidationException::withMessages(['volume' => 'The backup job no longer targets a selected stack volume.']);
                        }

                        $destination = $destinations->get($references['destination_id']);
                        $lockedJob->setRelation('destination', $destination);

                        return $this->createLocked($lockedJob, $trigger, $initiatedBy);
                    },
                    $current->isDockerVolumeSource() ? [$references['volume_name']] : [],
                    dockerHostId: (int) $current->docker_host_id,
                );
            } catch (RetryDockerLabelMutation) {
                continue;
            }
        }

        throw new RetryDockerLabelMutation('Backup job references kept changing concurrently.');
    }

    private function createLocked(BackupJob $job, string $trigger, ?User $initiatedBy): BackupRun
    {
        app(AgentExecution::class)->validateHost((int) $job->docker_host_id, 'backup-v1');
        app(HostWorkAdmission::class)->assertAccepting((int) $job->docker_host_id);
        $this->validateRunnable($job);

        $run = ($this->createBackupRunRecord ?? app(CreateBackupRunRecord::class))->handle($job, [
            'initiated_by_user_id' => $initiatedBy?->getKey(),
            'status' => BackupRun::STATUS_QUEUED,
            'trigger' => $trigger,
            'scheduled_for' => $trigger === BackupRun::TRIGGER_SCHEDULED ? $job->next_run_at : null,
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
    }

    /**
     * @return array{docker_host_id: int, destination_id: int, backup_job_group_id: ?int, configuration_source: string, source_type: string, volume_name: ?string, host_path: ?string}
     */
    private function references(BackupJob $job): array
    {
        return [
            'docker_host_id' => (int) $job->docker_host_id,
            'destination_id' => (int) $job->backup_destination_id,
            'backup_job_group_id' => $job->backup_job_group_id !== null ? (int) $job->backup_job_group_id : null,
            'configuration_source' => (string) $job->configuration_source,
            'source_type' => $job->sourceType(),
            'volume_name' => $job->volume_name,
            'host_path' => $job->host_path,
        ];
    }

    private function validateRunnable(BackupJob $job): void
    {
        if ($job->isGroupMember()) {
            throw ValidationException::withMessages(['job' => 'This job belongs to a backup group; run the group instead.']);
        }

        if ($job->status !== BackupJob::STATUS_ACTIVE || $job->pending_label_reconciliation !== null || $job->label_reconciliation_error !== null) {
            throw ValidationException::withMessages(['job' => 'Only active backup jobs with valid configuration can run.']);
        }

        if (! $job->destination?->is_active) {
            throw ValidationException::withMessages(['destination' => 'The backup destination is inactive.']);
        }

        if ($job->destination->isHostBound() && (int) $job->destination->docker_host_id !== (int) $job->docker_host_id) {
            throw ValidationException::withMessages(['destination' => 'The destination belongs to another Docker host.']);
        }

        if ($job->isDockerVolumeSource()) {
            $volume = DockerVolume::where('docker_host_id', $job->docker_host_id)->where('name', $job->volume_name)->first();

            if (! $volume?->isAvailable()) {
                throw ValidationException::withMessages(['volume' => 'Docker volume not found: '.$job->volume_name]);
            }
        }

        if (BackupRun::query()->where('backup_job_id', $job->id)->whereIn('status', [BackupRun::STATUS_QUEUED, BackupRun::STATUS_RUNNING])->exists()) {
            throw ValidationException::withMessages(['job' => 'A backup run is already queued or running for this job.']);
        }
    }
}
