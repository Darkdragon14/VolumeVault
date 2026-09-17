<?php

namespace App\Actions\Backup;

use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Services\Scheduling\BackupScheduleCalculator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ResumeBackupJob
{
    public function __construct(
        private readonly BackupScheduleCalculator $scheduleCalculator,
        private readonly WithBackupGroupMutationLocks $withGroupLocks,
        private readonly WithDockerLabelMutationLocks $withDockerLabelLocks,
    ) {}

    public function handle(BackupJob $job): BackupJob
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $current = BackupJob::query()->findOrFail($job->id);
            $references = $this->references($current);

            try {
                return $this->withGroupLocks->handle(
                    [$references['backup_job_group_id']],
                    fn (Collection $groups): BackupJob => $this->withDockerLabelLocks->handleForJobs(
                        $references['configuration_source'] === BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL ? [$job->id] : [],
                        [$references['destination_id']],
                        function ($destinations, $settings, $managedJobs, $volumes, $channels, $explicitJobs) use ($job, $references, $groups): BackupJob {
                            $lockedJob = $explicitJobs->get($job->id);

                            if (! $lockedJob instanceof BackupJob || $this->references($lockedJob) !== $references) {
                                throw new RetryDockerLabelMutation('Backup job references changed before they were locked.');
                            }

                            $lockedGroup = $references['backup_job_group_id'] === null
                                ? null
                                : $groups->get($references['backup_job_group_id']);

                            if ($references['backup_job_group_id'] !== null && ! $lockedGroup instanceof BackupJobGroup) {
                                throw new RetryDockerLabelMutation('Backup job group changed before it was locked.');
                            }

                            $this->validateResumable($lockedJob);

                            if ($lockedGroup?->status === BackupJobGroup::STATUS_ERROR) {
                                $lockedGroup->forceFill([
                                    'status' => BackupJobGroup::STATUS_ACTIVE,
                                    'last_error' => null,
                                    'last_error_at' => null,
                                    'next_run_at' => $this->scheduleCalculator->nextRunAt(
                                        $lockedGroup->schedule_type,
                                        $lockedGroup->schedule_config ?? [],
                                        null,
                                        $lockedGroup->timezone,
                                    ),
                                ])->save();
                            }

                            $lockedJob->forceFill([
                                'status' => BackupJob::STATUS_ACTIVE,
                                'pause_reason' => null,
                                'last_error' => null,
                                'last_error_at' => null,
                                'next_run_at' => $lockedGroup === null
                                    ? $this->scheduleCalculator->nextRunAt(
                                        $lockedJob->schedule_type,
                                        $lockedJob->schedule_config ?? [],
                                        null,
                                        $lockedJob->timezone,
                                    )
                                    : null,
                            ])->save();

                            return $lockedJob;
                        },
                        $current->isDockerVolumeSource() ? [$references['volume_name']] : [],
                        explicitJobIds: [$job->id],
                    ),
                );
            } catch (RetryDockerLabelMutation) {
                continue;
            }
        }

        throw new RetryDockerLabelMutation('Backup job references kept changing concurrently.');
    }

    private function validateResumable(BackupJob $job): void
    {
        if ($job->isDockerLabelManaged() && ($job->label_reconciliation_error !== null || $job->pending_label_reconciliation !== null)) {
            throw ValidationException::withMessages([
                'job' => 'This Docker label managed job cannot be resumed until its label configuration is valid.',
            ]);
        }

        if (! in_array($job->status, [BackupJob::STATUS_PAUSED, BackupJob::STATUS_ERROR], true)) {
            throw ValidationException::withMessages([
                'job' => 'Only paused or errored jobs can be resumed.',
            ]);
        }
    }

    /**
     * @return array{destination_id: int, backup_job_group_id: ?int, configuration_source: string, source_type: string, volume_name: ?string, host_path: ?string}
     */
    private function references(BackupJob $job): array
    {
        return [
            'destination_id' => (int) $job->backup_destination_id,
            'backup_job_group_id' => $job->backup_job_group_id !== null ? (int) $job->backup_job_group_id : null,
            'configuration_source' => (string) $job->configuration_source,
            'source_type' => $job->sourceType(),
            'volume_name' => $job->volume_name,
            'host_path' => $job->host_path,
        ];
    }
}
