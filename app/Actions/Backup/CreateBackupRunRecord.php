<?php

namespace App\Actions\Backup;

use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Services\Agents\AgentExecution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateBackupRunRecord
{
    public function __construct(private readonly RenderBackupFilename $renderBackupFilename) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(BackupJob $job, array $attributes, ?string $sourceVolumeName = null): BackupRun
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $current = BackupJob::query()->findOrFail($job->id);

            try {
                return DB::transaction(function () use ($current, $attributes, $sourceVolumeName): BackupRun {
                    $lockedDestination = BackupDestination::query()->lockForUpdate()->findOrFail($current->backup_destination_id);
                    $lockedJob = BackupJob::query()->lockForUpdate()->findOrFail($current->id);

                    if ($lockedJob->backup_destination_id !== $current->backup_destination_id) {
                        throw new RetryDockerLabelMutation('Backup destination changed before the run snapshot was locked.');
                    }

                    $lockedJob->setRelation('destination', $lockedDestination);
                    if (($attributes['trigger'] ?? null) !== BackupRun::TRIGGER_PRE_RESTORE && $lockedJob->retention_uuid === null) {
                        $lockedJob->forceFill(['retention_uuid' => (string) Str::uuid()])->save();
                    }
                    app(AgentExecution::class)->validateHost((int) $lockedJob->docker_host_id, 'backup-v1');
                    if ($lockedDestination->isHostBound() && (int) $lockedDestination->docker_host_id !== (int) $lockedJob->docker_host_id) {
                        throw ValidationException::withMessages(['destination' => 'The destination belongs to another Docker host.']);
                    }

                    $run = BackupRun::create([
                        ...$attributes,
                        'backup_job_id' => $lockedJob->id,
                        'docker_host_id' => $lockedJob->docker_host_id,
                        'source_type_snapshot' => $sourceVolumeName !== null ? BackupJob::SOURCE_TYPE_DOCKER_VOLUME : $lockedJob->sourceType(),
                        'source_volume_name' => $sourceVolumeName ?? ($lockedJob->isDockerVolumeSource() ? $lockedJob->volume_name : null),
                        'source_host_path' => $sourceVolumeName === null && $lockedJob->isHostPathSource() ? $lockedJob->host_path : null,
                        'backup_destination_id_snapshot' => $lockedDestination->id,
                        'backup_destination_name' => $lockedDestination->name,
                        'backup_destination_provider' => $lockedDestination->provider,
                        'backup_destination_locator_fingerprint' => $lockedDestination->locatorFingerprint(),
                        'execution_options_snapshot' => [
                            'backup_pruning_prefix' => ($attributes['trigger'] ?? null) === BackupRun::TRIGGER_PRE_RESTORE
                                ? null : 'volumevault-job-'.$lockedJob->retention_uuid.'-',
                            ...$lockedJob->only([
                                'retention_days',
                                'backup_exclude_regexp',
                                'backup_filter_mode',
                                'backup_include_paths',
                                'stop_containers_before_backup',
                                'stop_container_names',
                            ]),
                        ],
                    ]);

                    $run->setRelation('job', $lockedJob);
                    $run->forceFill(['backup_filename' => $this->renderBackupFilename->handle($run)])->save();

                    return $run;
                });
            } catch (RetryDockerLabelMutation) {
                continue;
            }
        }

        throw new RetryDockerLabelMutation('Backup destination kept changing while the run snapshot was created.');
    }
}
