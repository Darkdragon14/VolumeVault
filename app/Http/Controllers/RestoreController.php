<?php

namespace App\Http\Controllers;

use App\Actions\Restore\CreateRestoreRun;
use App\Actions\Restore\GenerateRestoreVolumeName;
use App\Actions\Restore\ResolveRestoreDestination;
use App\Actions\Runs\DispatchQueuedRun;
use App\Http\Requests\StoreRestoreRequest;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Models\DockerVolume;
use App\Services\Agents\AgentExecution;
use App\Services\BackupDestinations\ListBackupObjects;
use App\Services\Docker\LocalDockerExecution;
use App\Support\DeploymentMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class RestoreController extends Controller
{
    public function create(Request $request, BackupJob $backupJob, ListBackupObjects $listBackupObjects, GenerateRestoreVolumeName $generateRestoreVolumeName, ResolveRestoreDestination $resolveRestoreDestination): Response
    {
        $backupJob->load('destination');
        $validated = $request->validate(['backup_run_id' => ['nullable', 'integer'], 'docker_host_id' => ['nullable', 'integer', 'exists:docker_hosts,id']]);
        $backupRunId = isset($validated['backup_run_id']) ? (int) $validated['backup_run_id'] : null;
        $restoreDestination = $resolveRestoreDestination->handle($backupJob, $backupRunId);
        if ($restoreDestination->isHostBound() && (int) $restoreDestination->docker_host_id === DockerHost::LOCAL_ID) {
            LocalDockerExecution::validate();
        }
        $selectedBackupRun = $resolveRestoreDestination->backupRun($backupJob, $backupRunId);
        $sourceType = $selectedBackupRun?->sourceType() ?? $backupJob->sourceType();
        $sourceName = $selectedBackupRun?->sourceName() ?? $backupJob->sourceName();
        $listError = null;
        $backupRunUnverifiable = ListBackupObjects::isRunUnverifiable($restoreDestination, $selectedBackupRun);

        try {
            $backups = $backupRunUnverifiable ? [] : (($restoreDestination->isHostBound() && (int) $restoreDestination->docker_host_id !== DockerHost::LOCAL_ID) || (int) ($validated['docker_host_id'] ?? 1) !== 1
                ? $resolveRestoreDestination->knownRemoteBackups($backupJob, $restoreDestination, $selectedBackupRun)
                : $listBackupObjects->handleForRun($restoreDestination, $selectedBackupRun));
        } catch (Throwable) {
            $backups = [];
            $listError = 'Unable to list backups from this destination.';
        }

        $backups = $this->flagBackupsForJob($backups, $backupJob, $restoreDestination);
        $preselectedBackupKey = $selectedBackupRun?->backup_key ?? $request->query('backup');

        return Inertia::render('Restore/Create', [
            'destinationOperationHosts' => app(\App\Services\BackupDestinations\DestinationOperations::class)->hostOptions(),
            'hosts' => DockerHost::query()->when(DeploymentMode::isOrchestrator(), fn ($query) => $query->where('id', '!=', DockerHost::LOCAL_ID))->orderBy('name')->get()->map(fn (DockerHost $host): array => app(AgentExecution::class)->summary($host, includePaths: true)),
            'volumes' => DockerVolume::where('exists', true)->when(DeploymentMode::isOrchestrator(), fn ($query) => $query->where('docker_host_id', '!=', DockerHost::LOCAL_ID))->get(['docker_host_id', 'name']),
            'sourceDockerHostId' => $selectedBackupRun?->docker_host_id ?? $backupJob->docker_host_id,
            'targetDockerHostId' => $backupJob->docker_host_id,
            'job' => [
                ...$backupJob->toArray(),
                'destination' => $backupJob->destination ? [...$backupJob->destination->safeForFrontend(), 'docker_host_id' => $backupJob->destination->docker_host_id] : null,
                'is_docker_volume_source' => $backupJob->isDockerVolumeSource(),
            ],
            'restoreDestination' => [...$restoreDestination->safeForFrontend(), 'docker_host_id' => $restoreDestination->docker_host_id],
            'destinationOperations' => [
                'destination_id' => $restoreDestination->id,
                'default_docker_host_id' => $restoreDestination->isHostBound() ? $restoreDestination->docker_host_id : DockerHost::LOCAL_ID,
                'selected_docker_host_id' => $validated['docker_host_id'] ?? ($restoreDestination->isHostBound() ? $restoreDestination->docker_host_id : DockerHost::LOCAL_ID),
                'required_agent_capability' => 'destination-v1',
                'restore_receipt_field' => 'destination_operation_id',
            ],
            'backups' => $backups,
            // Whether some objects in the destination don't belong to this job, so
            // the wizard knows to offer a "show all backups" escape hatch.
            'hasOtherBackups' => collect($backups)->contains(fn (array $object) => ! ($object['belongs_to_job'] ?? false)),
            'preselectedBackupKey' => $preselectedBackupKey,
            'backupRunId' => $backupRunId,
            'backupRunUnverifiable' => $backupRunUnverifiable,
            'isDockerVolumeSource' => $sourceType === BackupJob::SOURCE_TYPE_DOCKER_VOLUME
                && ($selectedBackupRun === null || $selectedBackupRun->source_type_snapshot !== null),
            'sourceVolumeName' => $sourceType === BackupJob::SOURCE_TYPE_DOCKER_VOLUME ? $sourceName : null,
            'sourceLabel' => $sourceName,
            'listError' => $listError,
            'generatedTargetVolumeName' => DeploymentMode::isOrchestrator() && (int) $backupJob->docker_host_id === DockerHost::LOCAL_ID
                ? null : $generateRestoreVolumeName->handle($sourceName, dockerHostId: (int) $backupJob->docker_host_id),
        ]);
    }

    /**
     * Tag each destination object with whether it belongs to this backup job.
     *
     * A destination can hold backups from several jobs; the wizard defaults to
     * this job's own archives. Matching uses the `backup_key` recorded on the
     * job's successful runs, which is more reliable than reconstructing the
     * filename template. Provider keys are opaque and must match exactly.
     *
     * @param  array<int, array<string, mixed>>  $backups
     * @return array<int, array<string, mixed>>
     */
    private function flagBackupsForJob(array $backups, BackupJob $backupJob, BackupDestination $destination): array
    {
        $jobKeys = BackupRun::query()
            ->where('backup_job_id', $backupJob->id)
            ->where('status', BackupRun::STATUS_SUCCESS)
            ->whereNotNull('backup_key')
            ->where(function ($query) use ($backupJob, $destination): void {
                $query->where(function ($query) use ($destination): void {
                    $query->where('backup_destination_id_snapshot', $destination->id)
                        ->where('backup_destination_locator_fingerprint', $destination->locatorFingerprint());
                });

                if ($backupJob->backup_destination_id === $destination->id) {
                    $query->orWhere(function ($query): void {
                        $query->whereNull('backup_destination_id_snapshot')
                            ->whereNull('backup_destination_locator_fingerprint');
                    });
                }
            })
            ->pluck('backup_key')
            ->filter()
            ->unique()
            ->values();

        return collect($backups)->map(function (array $object) use ($jobKeys): array {
            $object['belongs_to_job'] = $jobKeys->contains(function (string $key) use ($object): bool {
                return (string) ($object['key'] ?? '') === $key;
            });

            return $object;
        })->all();
    }

    public function listBackups(Request $request, BackupJob $backupJob, ListBackupObjects $listBackupObjects, ResolveRestoreDestination $resolveRestoreDestination): JsonResponse
    {
        $validated = $request->validate(['backup_run_id' => ['nullable', 'integer'], 'docker_host_id' => ['nullable', 'integer', 'exists:docker_hosts,id'], 'cursor' => ['nullable', 'string', 'max:32768'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:1000']]);
        $destination = $resolveRestoreDestination->handle(
            $backupJob,
            isset($validated['backup_run_id']) ? (int) $validated['backup_run_id'] : null,
        );
        $run = $resolveRestoreDestination->backupRun(
            $backupJob,
            isset($validated['backup_run_id']) ? (int) $validated['backup_run_id'] : null,
        );

        if (ListBackupObjects::isRunUnverifiable($destination, $run)) {
            return response()->json(['message' => ListBackupObjects::UNVERIFIABLE_RUN_MESSAGE], 422);
        }

        $operations = app(\App\Services\BackupDestinations\DestinationOperations::class);
        if (! isset($validated['docker_host_id']) && $destination->isHostBound() && (int) $destination->docker_host_id !== DockerHost::LOCAL_ID
            && ! app(AgentExecution::class)->supportsHost($destination->dockerHost, 'destination-v1')) {
            return response()->json(['backups' => $resolveRestoreDestination->knownRemoteBackups($backupJob, $destination, $run), 'listing_supported' => false]);
        }
        $hostId = $operations->hostId($destination, isset($validated['docker_host_id']) ? (int) $validated['docker_host_id'] : null);
        if ($hostId !== DockerHost::LOCAL_ID) {
            return response()->json(['data' => $operations->safe($operations->create($destination, 'list', $hostId, $validated['cursor'] ?? null, (int) ($validated['limit'] ?? 1000), $run?->id))], 202);
        }

        if ($destination->isHostBound()) {
            LocalDockerExecution::validate();
        }

        try {
            $backups = $listBackupObjects->handleForRun($destination, $run);
        } catch (Throwable) {
            return response()->json(['message' => 'Unable to list backups from this destination.'], 502);
        }

        return response()->json(['backups' => $backups]);
    }

    public function store(StoreRestoreRequest $request, BackupJob $backupJob, CreateRestoreRun $createRestoreRun, DispatchQueuedRun $dispatchQueuedRun)
    {
        $run = $createRestoreRun->handle($backupJob, $request->validated(), $request->user());
        $dispatchQueuedRun->handle($run);

        return redirect()->route('restore-runs.show', $run)->with('success', 'Restore run queued.');
    }
}
