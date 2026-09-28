<?php

namespace App\Actions\Backup;

use App\Models\BackupJob;

class DeleteBackupJob
{
    public function __construct(
        private readonly WithDockerLabelMutationLocks $withLocks,
        private readonly WithBackupGroupMutationLocks $withGroupLocks,
    ) {}

    public function handle(BackupJob $job): void
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $current = BackupJob::query()->findOrFail($job->id);
            $references = $this->references($current);

            try {
                $this->withGroupLocks->handle([$references['backup_job_group_id']], function () use ($current, $job, $references): void {
                    $this->withLocks->handleForJobsOnHost(
                        $current->isDockerLabelManaged() ? [$current->id] : [],
                        [$references['destination_id']],
                        function ($destinations, $settings, $managedJobs) use ($job, $references): void {
                            $lockedJob = $references['configuration_source'] === BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL
                                ? $managedJobs->get($job->id)
                                : BackupJob::query()->lockForUpdate()->find($job->id);

                            if (! $lockedJob || $this->references($lockedJob) !== $references) {
                                throw new RetryDockerLabelMutation('Backup job references changed before they were locked.');
                            }

                            if ($lockedJob->isDockerLabelManaged()) {
                                throw new BackupJobDeletionRejected(BackupJobDeletionRejected::READ_ONLY);
                            }

                            if ($lockedJob->hasRunInProgress()) {
                                throw new BackupJobDeletionRejected(BackupJobDeletionRejected::RUN_IN_PROGRESS);
                            }

                            if ($lockedJob->hasOutstandingFinalizations()) {
                                throw new BackupJobDeletionRejected(BackupJobDeletionRejected::FINALIZATION_PENDING);
                            }

                            $lockedJob->delete();
                        },
                        $current->isDockerVolumeSource() ? [$references['volume_name']] : [],
                        dockerHostId: $current->docker_host_id,
                    );
                });

                return;
            } catch (RetryDockerLabelMutation) {
                continue;
            }
        }

        throw new RetryDockerLabelMutation('Backup job references kept changing concurrently.');
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
}
