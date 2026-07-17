<?php

namespace App\Services\Volumes;

use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerVolume;
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
    public function forVolumes(Collection $volumes): Collection
    {
        $volumes = $volumes->values();
        $volumeNames = $volumes->pluck('name')->filter()->values();
        $hostIds = $volumes->pluck('host_id')->filter()->unique()->values();

        $jobsByVolume = $volumeNames->isEmpty()
            ? collect()
            : BackupJob::query()
                ->where('source_type', BackupJob::SOURCE_TYPE_DOCKER_VOLUME)
                ->whereIn('host_id', $hostIds->all())
                ->whereIn('volume_name', $volumeNames->all())
                ->get(['id', 'host_id', 'name', 'volume_name', 'status'])
                ->groupBy(fn (BackupJob $job): string => $this->volumeKey($job->host_id, $job->volume_name));

        $runsByVolume = $volumeNames->isEmpty()
            ? collect()
            : BackupRun::query()
                ->select('backup_runs.*', 'backup_jobs.host_id as summary_host_id', 'backup_jobs.volume_name as summary_volume_name')
                ->join('backup_jobs', 'backup_jobs.id', '=', 'backup_runs.backup_job_id')
                ->where('backup_jobs.source_type', BackupJob::SOURCE_TYPE_DOCKER_VOLUME)
                ->whereIn('backup_jobs.host_id', $hostIds->all())
                ->whereIn('backup_jobs.volume_name', $volumeNames->all())
                ->where('backup_runs.status', BackupRun::STATUS_SUCCESS)
                ->orderByDesc('backup_runs.finished_at')
                ->orderByDesc('backup_runs.created_at')
                ->get()
                ->groupBy(fn (BackupRun $run): string => $this->volumeKey($run->summary_host_id, $run->summary_volume_name));

        return $volumes->map(function (DockerVolume $volume) use ($jobsByVolume, $runsByVolume): array {
            $key = $this->volumeKey($volume->host_id, $volume->name);
            $jobs = $jobsByVolume->get($key, collect());
            $lastSuccessfulRun = $runsByVolume->get($key, collect())->first();

            return [
                ...$volume->toArray(),
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
    public function forStacks(Collection $volumes): Collection
    {
        return $this->forVolumes($volumes)
            ->groupBy(fn (array $volume): string => $volume['stack_name'] ?? '')
            ->map(function (Collection $volumes, string $stackName): array {
                $existingVolumes = $volumes->where('exists', true);
                $configuredJobVolumes = $existingVolumes->filter(fn (array $volume): bool => (int) ($volume['related_jobs_count'] ?? 0) > 0)->count();
                $lastBackup = $volumes
                    ->filter(fn (array $volume): bool => filled($volume['last_backup_at'] ?? null))
                    ->sortByDesc('last_backup_at')
                    ->first();

                return [
                    'name' => $stackName !== '' ? $stackName : null,
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

    private function volumeKey(int|string|null $hostId, ?string $volumeName): string
    {
        return (string) $hostId."\0".(string) $volumeName;
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
