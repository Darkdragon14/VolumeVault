<?php

namespace App\Services\Volumes;

use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Models\DockerVolume;
use App\Services\Agents\OperationalHostScope;
use Illuminate\Support\Collection;

class VolumeBackupSummaries
{
    public const STATE_BACKED_UP = 'backed_up';

    public const STATE_CONFIGURED = 'configured';

    public const STATE_UNPROTECTED = 'unprotected';

    public const STACK_CONFIGURED = 'configured';

    public const STACK_PARTIALLY_CONFIGURED = 'partially_configured';

    public const STACK_NOT_CONFIGURED = 'not_configured';

    /**
     * @param  Collection<int, DockerVolume>  $volumes
     * @return Collection<int, array<string, mixed>>
     */
    public function forVolumes(Collection $volumes, ?OperationalHostScope $scope = null): Collection
    {
        $volumes = $volumes->values();
        $scope ??= app(OperationalHostScope::class);
        $volumeNames = $volumes->pluck('name')->filter()->values();
        $hostIds = $volumes->pluck('docker_host_id')->unique()->values();

        $jobsByVolume = $volumeNames->isEmpty()
            ? collect()
            : BackupJob::query()
                ->reservingDockerVolumes()
                ->whereIn('docker_host_id', $hostIds->all())
                ->where('source_type', BackupJob::SOURCE_TYPE_DOCKER_VOLUME)
                ->whereIn('volume_name', $volumeNames->all())
                ->get(['id', 'docker_host_id', 'name', 'volume_name', 'status'])
                ->groupBy(fn (BackupJob $job): string => $job->docker_host_id.':'.$job->volume_name);

        $runsByVolume = $volumeNames->isEmpty()
            ? collect()
            : BackupRun::query()
                ->select('backup_runs.*')
                ->selectRaw('COALESCE(backup_runs.source_volume_name, backup_jobs.volume_name) as summary_volume_name')
                ->join('backup_jobs', 'backup_jobs.id', '=', 'backup_runs.backup_job_id')
                ->whereIn('backup_runs.docker_host_id', $hostIds->all())
                ->where(function ($query) use ($volumeNames): void {
                    $query->where(function ($query) use ($volumeNames): void {
                        $query->where('backup_runs.source_type_snapshot', BackupJob::SOURCE_TYPE_DOCKER_VOLUME)
                            ->whereIn('backup_runs.source_volume_name', $volumeNames->all());
                    })->orWhere(function ($query) use ($volumeNames): void {
                        $query->whereNull('backup_runs.source_type_snapshot')
                            ->whereColumn('backup_runs.docker_host_id', 'backup_jobs.docker_host_id')
                            ->where('backup_jobs.source_type', BackupJob::SOURCE_TYPE_DOCKER_VOLUME)
                            ->whereIn('backup_jobs.volume_name', $volumeNames->all())
                            ->where(function ($query): void {
                                $query->where('backup_jobs.configuration_source', '!=', BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL)
                                    ->orWhereNull('backup_jobs.configuration_source')
                                    ->orWhereNull('backup_jobs.label_reconciliation_error');
                            });
                    });
                })
                ->where('backup_runs.status', BackupRun::STATUS_SUCCESS)
                ->orderByDesc('backup_runs.finished_at')
                ->orderByDesc('backup_runs.created_at')
                ->get()
                ->groupBy(fn (BackupRun $run): string => $run->docker_host_id.':'.$run->summary_volume_name);

        return $volumes->map(function (DockerVolume $volume) use ($jobsByVolume, $runsByVolume, $scope): array {
            $key = $volume->docker_host_id.':'.$volume->name;
            $jobs = $jobsByVolume->get($key, collect());
            $lastSuccessfulRun = $runsByVolume->get($key, collect())->first();
            $host = $scope->summary((int) $volume->docker_host_id);

            return [
                ...$volume->toArray(),
                'identity' => $key,
                'docker_host' => $host,
                'canSync' => $host['canSync'] ?? false,
                'canBackup' => $volume->getAttribute('exists') && ($host['canBackup'] ?? false),
                'backup_unavailable_reason' => ! $volume->getAttribute('exists') ? 'volume_missing' : ($host['backup_unavailable_reason'] ?? null),
                'create_job_url' => route('backup-jobs.create', ['volume' => $volume->name, 'docker_host_id' => $volume->docker_host_id]),
                'stack_name' => $this->stackName($volume),
                'related_jobs_count' => $jobs->count(),
                'backup_state' => $this->backupState($jobs->count(), $lastSuccessfulRun),
                'last_backup_run_id' => $lastSuccessfulRun?->id,
                'last_backup_at' => $lastSuccessfulRun?->finished_at ?? $lastSuccessfulRun?->created_at,
                'last_backup_key' => $lastSuccessfulRun?->backup_key,
                'last_backup_size_bytes' => $lastSuccessfulRun?->backup_size_bytes,
            ];
        });
    }

    /**
     * @param  Collection<int, DockerVolume>  $volumes
     * @return Collection<int, array<string, mixed>>
     */
    public function forStacks(Collection $volumes, ?OperationalHostScope $scope = null): Collection
    {
        return $this->forVolumes($volumes, $scope)
            ->groupBy(fn (array $volume): string => $volume['docker_host_id'].':'.($volume['stack_name'] ?? ''))
            ->map(function (Collection $volumes): array {
                $existingVolumes = $volumes->where('exists', true);
                $configuredJobVolumes = $existingVolumes->filter(fn (array $volume): bool => (int) ($volume['related_jobs_count'] ?? 0) > 0)->count();
                $lastBackup = $volumes
                    ->filter(fn (array $volume): bool => filled($volume['last_backup_at'] ?? null))
                    ->sortByDesc('last_backup_at')
                    ->first();

                return [
                    'name' => $volumes->first()['stack_name'],
                    'inventory_basis' => 'volume_labels',
                    'container_count' => null,
                    'docker_host_id' => $volumes->first()['docker_host_id'],
                    'identity' => $volumes->first()['docker_host_id'].':'.($volumes->first()['stack_name'] ?? ''),
                    'docker_host' => $volumes->first()['docker_host'],
                    'canSync' => $volumes->first()['canSync'],
                    'canBackup' => $volumes->first()['docker_host_id'] === DockerHost::LOCAL_ID && $existingVolumes->contains('canBackup', true),
                    'backup_unavailable_reason' => $volumes->first()['docker_host_id'] !== DockerHost::LOCAL_ID ? 'remote_stack_backup_unsupported' : ($volumes->first()['docker_host']['backup_unavailable_reason'] ?? ($existingVolumes->isEmpty() ? 'no_volumes' : null)),
                    'total_volumes' => $volumes->count(),
                    'existing_volumes' => $existingVolumes->count(),
                    'missing_volumes' => $volumes->where('exists', false)->count(),
                    'configured_job_volumes' => $configuredJobVolumes,
                    'configuration_state' => $this->stackConfigurationState($existingVolumes->count(), $configuredJobVolumes),
                    'backed_up_volumes' => $existingVolumes->where('backup_state', self::STATE_BACKED_UP)->count(),
                    'configured_volumes' => $existingVolumes->where('backup_state', self::STATE_CONFIGURED)->count(),
                    'unprotected_volumes' => $existingVolumes->where('backup_state', self::STATE_UNPROTECTED)->count(),
                    'last_backup_at' => $lastBackup['last_backup_at'] ?? null,
                    'last_backup_size_bytes' => $lastBackup['last_backup_size_bytes'] ?? null,
                    'volumes' => $volumes->values(),
                ];
            })
            ->sortBy(fn (array $stack): string => $stack['name'] === null ? 'zzzzzz' : strtolower($stack['name']))
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $volumeSummaries
     * @return array<string, int>
     */
    public function coverageStats(Collection $volumeSummaries): array
    {
        $existingVolumes = $volumeSummaries->where('exists', true);

        return [
            'backed_up_volumes' => $existingVolumes->where('backup_state', self::STATE_BACKED_UP)->count(),
            'configured_volumes' => $existingVolumes->where('backup_state', self::STATE_CONFIGURED)->count(),
            'unprotected_volumes' => $existingVolumes->where('backup_state', self::STATE_UNPROTECTED)->count(),
        ];
    }

    public function stackName(DockerVolume $volume): ?string
    {
        $labels = $volume->labels ?? [];

        if (! is_array($labels)) {
            return null;
        }

        $composeProject = $labels['com.docker.compose.project'] ?? null;
        $swarmStack = $labels['com.docker.stack.namespace'] ?? null;

        return filled($composeProject) ? (string) $composeProject : (filled($swarmStack) ? (string) $swarmStack : null);
    }

    private function backupState(int $jobCount, ?BackupRun $lastSuccessfulRun): string
    {
        if ($lastSuccessfulRun) {
            return self::STATE_BACKED_UP;
        }

        return $jobCount > 0 ? self::STATE_CONFIGURED : self::STATE_UNPROTECTED;
    }

    private function stackConfigurationState(int $existingVolumeCount, int $configuredJobVolumeCount): string
    {
        if ($existingVolumeCount > 0 && $configuredJobVolumeCount === $existingVolumeCount) {
            return self::STACK_CONFIGURED;
        }

        if ($configuredJobVolumeCount > 0) {
            return self::STACK_PARTIALLY_CONFIGURED;
        }

        return self::STACK_NOT_CONFIGURED;
    }
}
