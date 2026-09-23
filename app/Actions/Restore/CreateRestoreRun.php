<?php

namespace App\Actions\Restore;

use App\Actions\Backup\RetryDockerLabelMutation;
use App\Actions\Backup\WithDockerLabelMutationLocks;
use App\Models\ActivityLog;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Models\DockerVolume;
use App\Models\RestoreRun;
use App\Models\User;
use App\Services\Agents\AgentExecution;
use App\Services\Agents\HostWorkAdmission;
use App\Services\BackupDestinations\ListBackupObjects;
use App\Services\Docker\DockerVolumeName;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateRestoreRun
{
    public const DROPBOX_SAFETY_BACKUP_MESSAGE = 'Safety backup before overwrite is unavailable because the job’s current destination is Dropbox. A newly uploaded Dropbox backup cannot be verified for restore. Choose a different job destination or explicitly turn off the safety backup.';

    public function __construct(
        private readonly GenerateRestoreVolumeName $generateRestoreVolumeName,
        private readonly WithDockerLabelMutationLocks $withLocks,
        private readonly ResolveRestoreDestination $resolveRestoreDestination,
        private readonly ListBackupObjects $listBackupObjects,
    ) {}

    public function handle(BackupJob $job, array $data, ?User $initiatedBy = null): RestoreRun
    {
        $mode = $data['mode'] ?? RestoreRun::MODE_NEW_VOLUME;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $current = BackupJob::query()->findOrFail($job->id);
            $targetHostId = (int) ($data['target_docker_host_id'] ?? $current->docker_host_id);
            $data['target_docker_host_id'] = $targetHostId;
            app(AgentExecution::class)->validateHost($targetHostId, 'restore-v1');
            app(HostWorkAdmission::class)->assertAccepting($targetHostId);
            $this->validateSafetyBackup($current->destination, $mode, $data);
            $references = $this->references($current);
            $backupRunId = isset($data['backup_run_id']) ? (int) $data['backup_run_id'] : null;
            $selectedBackupRun = $this->resolveRestoreDestination->backupRun($current, $backupRunId);
            $sourceContext = $this->sourceContext($current, $selectedBackupRun);
            $restoreDestination = $this->resolveRestoreDestination->handle($current, $backupRunId);
            if ($restoreDestination->isHostBound() && (int) $restoreDestination->docker_host_id !== $targetHostId) {
                app(\App\Services\Agents\ArchiveRelays::class)->validate($restoreDestination, $targetHostId, $mode);
            }
            if ($restoreDestination->isHostBound() && (int) $restoreDestination->docker_host_id !== DockerHost::LOCAL_ID && $selectedBackupRun === null && empty($data['destination_operation_id'])) {
                throw ValidationException::withMessages(['backup_run_id' => 'Select a known successful backup run for an agent-owned local destination.']);
            }
            $selectedBackupKey = $this->validateSelectedBackupKey(
                $selectedBackupRun,
                $restoreDestination,
                (string) $data['selected_backup_key'],
            );
            $validatedBackupRunKey = $selectedBackupRun?->backup_key;
            $restoreDestinationId = (int) $restoreDestination->id;
            $restoreDestinationFingerprint = $this->destinationFingerprint($restoreDestination);
            $selectedBackupKeyAvailability = isset($data['destination_operation_id'])
                ? ['available' => app(\App\Services\BackupDestinations\DestinationOperations::class)->verifies($restoreDestination, $restoreDestination->isHostBound() ? (int) $restoreDestination->docker_host_id : $targetHostId, $data['destination_operation_id'], $selectedBackupKey), 'exception' => null]
                : $this->selectedBackupKeyAvailability(
                $restoreDestination,
                $selectedBackupKey,
                $selectedBackupRun !== null,
            );
            $targetVolume = $this->isInPlace($mode)
                ? null
                : $this->resolveNewVolumeTarget($sourceContext['type'], $sourceContext['name'], $data['target_volume_name'] ?? null, $sourceContext['docker_host_id'], $targetHostId);

            try {
                return $this->withLocks->handleForJobsOnHost(
                    $targetHostId === DockerHost::LOCAL_ID && $references['configuration_source'] === BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL ? [$job->id] : [],
                    array_values(array_unique([$references['destination_id'], $restoreDestinationId])),
                    function ($destinations, $settings, $jobs, $volumes) use ($job, $data, $initiatedBy, $mode, $references, $sourceContext, $targetVolume, $backupRunId, $restoreDestinationId, $restoreDestinationFingerprint, $selectedBackupKeyAvailability, $validatedBackupRunKey, $selectedBackupKey): RestoreRun {
                        $lockedRestoreDestination = $destinations->get($restoreDestinationId);

                        if (! $lockedRestoreDestination || $this->destinationFingerprint($lockedRestoreDestination) !== $restoreDestinationFingerprint) {
                            throw new RetryDockerLabelMutation('Restore destination configuration changed before it was locked.');
                        }

                        if (! $lockedRestoreDestination->is_active) {
                            throw ValidationException::withMessages(['destination' => 'The backup destination is inactive.']);
                        }

                        $lockedJob = (int) $data['target_docker_host_id'] === DockerHost::LOCAL_ID && $references['configuration_source'] === BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL
                            ? $jobs->get($job->id)
                            : BackupJob::query()->lockForUpdate()->find($job->id);

                        if (! $lockedJob || $this->references($lockedJob) !== $references) {
                            throw new RetryDockerLabelMutation('Backup job references changed before they were locked.');
                        }

                        $currentDestination = $destinations->get($references['destination_id']);

                        if (! $currentDestination) {
                            throw new RetryDockerLabelMutation('Backup job destination changed before it was locked.');
                        }

                        $lockedJob->setRelation('destination', $currentDestination);
                        $this->validateSafetyBackup($currentDestination, $mode, $data);
                        $lockedBackupRun = $this->resolveRestoreDestination->backupRun($lockedJob, $backupRunId, true);

                        if ($lockedBackupRun?->backup_key !== $validatedBackupRunKey) {
                            throw new RetryDockerLabelMutation('Selected backup run changed before it was locked.');
                        }

                        $lockedSourceContext = $this->sourceContext($lockedJob, $lockedBackupRun);

                        if ($lockedSourceContext !== $sourceContext) {
                            throw new RetryDockerLabelMutation('Restore source context changed before it was locked.');
                        }

                        $restoreDestination = $this->resolveRestoreDestination->handle($lockedJob, $backupRunId, true);

                        if ((int) $restoreDestination->id !== $restoreDestinationId) {
                            throw new RetryDockerLabelMutation('Restore destination changed before it was locked.');
                        }

                        if ($selectedBackupKeyAvailability['exception'] !== null) {
                            throw ValidationException::withMessages([
                                'selected_backup_key' => 'Unable to verify the selected backup against the destination listing.',
                            ]);
                        }

                        if (! $selectedBackupKeyAvailability['available']) {
                            throw ValidationException::withMessages([
                                'selected_backup_key' => 'The selected backup is not available on the destination.',
                            ]);
                        }

                        return $this->createLocked($lockedJob, $lockedRestoreDestination, $data, $selectedBackupKey, $mode, $lockedSourceContext, $targetVolume, $initiatedBy, $volumes->get($lockedSourceContext['name']));
                    },
                    array_values(array_unique(array_filter([
                        $current->isDockerVolumeSource() ? $references['volume_name'] : null,
                        $sourceContext['type'] === BackupJob::SOURCE_TYPE_DOCKER_VOLUME ? $sourceContext['name'] : null,
                    ]))),
                    dockerHostId: $targetHostId,
                );
            } catch (RetryDockerLabelMutation) {
                continue;
            }
        }

        throw new RetryDockerLabelMutation('Backup job references kept changing concurrently.');
    }

    /** @return array{available: bool, exception: ?Throwable} */
    private function selectedBackupKeyAvailability(BackupDestination $destination, string $key, bool $exhaustive): array
    {
        if ($destination->isHostBound() && (int) $destination->docker_host_id !== DockerHost::LOCAL_ID) {
            return ['available' => $exhaustive, 'exception' => null];
        }

        try {
            $available = $this->listBackupObjects->contains($destination, $key, $exhaustive);
        } catch (Throwable $exception) {
            return ['available' => false, 'exception' => $exception];
        }

        return ['available' => $available, 'exception' => null];
    }

    private function validateSelectedBackupKey(?BackupRun $backupRun, BackupDestination $destination, string $selectedBackupKey): string
    {
        if ($backupRun === null) {
            return $selectedBackupKey;
        }

        if (ListBackupObjects::isRunUnverifiable($destination, $backupRun)) {
            throw ValidationException::withMessages([
                'selected_backup_key' => ListBackupObjects::UNVERIFIABLE_RUN_MESSAGE,
            ]);
        }

        if ($backupRun->backup_key !== $selectedBackupKey) {
            throw ValidationException::withMessages([
                'selected_backup_key' => 'The selected backup does not belong to the selected backup run.',
            ]);
        }

        return $selectedBackupKey;
    }

    private function destinationFingerprint(BackupDestination $destination): string
    {
        $storageAttributes = [
            'docker_host_id',
            'provider',
            'endpoint',
            'region',
            'bucket',
            'path_prefix',
            'access_key_id',
            'secret_access_key',
            'use_path_style_endpoint',
            'settings',
            'secrets',
            'is_active',
        ];

        return hash('sha256', serialize(collect($storageAttributes)
            ->mapWithKeys(fn (string $attribute): array => [$attribute => $destination->getRawOriginal($attribute)])
            ->all()));
    }

    /** @param array{type: string, name: string, docker_host_id: int, in_place_supported: bool} $sourceContext */
    private function createLocked(BackupJob $job, BackupDestination $restoreDestination, array $data, string $selectedBackupKey, string $mode, array $sourceContext, ?string $targetVolume, ?User $initiatedBy, ?DockerVolume $sourceVolume): RestoreRun
    {
        $targetHostId = (int) $data['target_docker_host_id'];
        app(AgentExecution::class)->validateHost($targetHostId, 'restore-v1');
        app(HostWorkAdmission::class)->assertAccepting($targetHostId);
        $sourceName = $sourceContext['name'];
        $targetVolume = $this->isInPlace($mode)
            ? $this->resolveInPlaceTarget($sourceContext['type'], $sourceName, $sourceContext['in_place_supported'], $data['confirmation_text'] ?? null)
            : (string) $targetVolume;

        if ($this->isInPlace($mode) && ! $sourceVolume?->isAvailable()) {
            throw ValidationException::withMessages([
                'mode' => 'In-place restore requires an available source Docker volume.',
            ]);
        }

        if ($this->isInPlace($mode) && ($data['backup_before_overwrite'] ?? false)
            && $targetHostId !== (int) $job->docker_host_id) {
            throw ValidationException::withMessages(['backup_before_overwrite' => 'Safety backup requires a job configured for this target host.']);
        }
        if ($this->isInPlace($mode) && ($data['backup_before_overwrite'] ?? false)) {
            app(AgentExecution::class)->validateHost($targetHostId, 'backup-v1');
            if (! $job->destination?->is_active || ($job->destination->isHostBound() && (int) $job->destination->docker_host_id !== $targetHostId)) {
                throw ValidationException::withMessages(['backup_before_overwrite' => 'The safety backup destination is unavailable on the target host.']);
            }
        }

        $run = RestoreRun::create([
            'backup_job_id' => $job->id,
            'source_docker_host_id' => $sourceContext['docker_host_id'],
            'target_docker_host_id' => $targetHostId,
            'initiated_by_user_id' => $initiatedBy?->getKey(),
            'backup_destination_id' => $restoreDestination->id,
            'selected_backup_key' => $selectedBackupKey,
            'source_volume_name' => $sourceName,
            'target_volume_name' => $targetVolume,
            'mode' => $mode,
            // Only the destructive in-place modes wipe an existing volume, so the
            // safety backup is meaningless (and the toggle hidden) otherwise.
            'backup_before_overwrite' => $this->isInPlace($mode) ? (bool) ($data['backup_before_overwrite'] ?? false) : false,
            'status' => RestoreRun::STATUS_QUEUED,
            'confirmation_text' => $data['confirmation_text'] ?? null,
        ]);

        ActivityLog::record('restore_run_started', 'Restore run queued.', $run, [
            'backup_job_id' => $job->id,
            'target_volume_name' => $targetVolume,
        ]);

        if (app(\App\Services\Agents\ArchiveRelays::class)->required($restoreDestination, $targetHostId)) {
            app(\App\Services\Agents\ArchiveRelays::class)->create($run, $restoreDestination, isset($data['backup_run_id']) ? (int) $data['backup_run_id'] : null);
        }

        return $run;
    }

    private function validateSafetyBackup(?BackupDestination $destination, string $mode, array $data): void
    {
        if ($this->isInPlace($mode) && ($data['backup_before_overwrite'] ?? false) && $destination?->provider === BackupDestination::PROVIDER_DROPBOX) {
            throw ValidationException::withMessages([
                'backup_before_overwrite' => self::DROPBOX_SAFETY_BACKUP_MESSAGE,
            ]);
        }
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
    private function resolveInPlaceTarget(string $sourceType, string $sourceName, bool $inPlaceSupported, ?string $confirmationText): string
    {
        if (! $inPlaceSupported) {
            throw ValidationException::withMessages([
                'mode' => 'This historical backup does not contain a source snapshot and cannot be restored in place.',
            ]);
        }

        if ($sourceType !== BackupJob::SOURCE_TYPE_DOCKER_VOLUME) {
            throw ValidationException::withMessages([
                'mode' => 'In-place restore is only available for Docker volume sources.',
            ]);
        }

        if ((string) $confirmationText !== $sourceName) {
            throw ValidationException::withMessages([
                'confirmation_text' => 'Type the exact volume name to confirm this in-place restore.',
            ]);
        }

        return $sourceName;
    }

    private function resolveNewVolumeTarget(string $sourceType, string $sourceName, ?string $requested, int $sourceHostId, int $targetHostId): string
    {
        $targetVolume = ($requested ?: null) ?: $this->generateRestoreVolumeName->handle($sourceName, dockerHostId: $targetHostId);

        if (! DockerVolumeName::isValidName($targetVolume) || mb_strlen($targetVolume) > 128) {
            throw ValidationException::withMessages(['target_volume_name' => 'The target Docker volume name is invalid.']);
        }

        if ($targetHostId !== DockerHost::LOCAL_ID && DockerVolume::where('docker_host_id', $targetHostId)->where('name', $targetVolume)->where('exists', true)->exists()) {
            throw ValidationException::withMessages(['target_volume_name' => 'The target Docker volume already exists.']);
        }

        if ($sourceHostId === $targetHostId && $sourceType === BackupJob::SOURCE_TYPE_DOCKER_VOLUME && $targetVolume === $sourceName) {
            throw ValidationException::withMessages([
                'target_volume_name' => 'Restore-to-new-volume cannot use the source volume name.',
            ]);
        }

        return $targetVolume;
    }

    /** @return array{type: string, name: string, docker_host_id: int, in_place_supported: bool} */
    private function sourceContext(BackupJob $job, ?BackupRun $backupRun): array
    {
        return [
            'type' => $backupRun?->sourceType() ?? $job->sourceType(),
            'name' => $backupRun?->sourceName() ?? $job->sourceName(),
            'docker_host_id' => $backupRun?->docker_host_id ?? $job->docker_host_id,
            'in_place_supported' => $backupRun === null || $backupRun->source_type_snapshot !== null,
        ];
    }

    /**
     * @return array{docker_host_id: int, destination_id: int, backup_job_group_id: ?int, configuration_source: string, source_type: string, volume_name: ?string, host_path: ?string}
     */
    private function references(BackupJob $job): array
    {
        return [
            'docker_host_id' => $job->docker_host_id,
            'destination_id' => (int) $job->backup_destination_id,
            'backup_job_group_id' => $job->backup_job_group_id !== null ? (int) $job->backup_job_group_id : null,
            'configuration_source' => (string) $job->configuration_source,
            'source_type' => $job->sourceType(),
            'volume_name' => $job->volume_name,
            'host_path' => $job->host_path,
        ];
    }
}
