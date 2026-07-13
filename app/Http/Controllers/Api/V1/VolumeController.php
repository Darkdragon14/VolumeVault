<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Docker\SyncDockerVolumes;
use App\Http\Controllers\Api\V1\Concerns\ResolvesApiHosts;
use App\Http\Controllers\Controller;
use App\Models\DockerVolume;
use App\Services\Volumes\VolumeBackupSummaries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class VolumeController extends Controller
{
    public function index(VolumeBackupSummaries $volumeBackupSummaries): JsonResponse
    {
        $volumes = DockerVolume::query()
            ->orderByDesc('exists')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $volumeBackupSummaries->forVolumes($volumes),
        ]);
    }

    public function sync(Request $request, SyncDockerVolumes $syncDockerVolumes): JsonResponse
    {
        try {
            return response()->json(['data' => $this->syncHosts($request, $syncDockerVolumes)]);
        } catch (Throwable $exception) {
            return response()->json([
                'message' => 'Unable to sync Docker volumes.',
                'error' => str($exception->getMessage())->limit(500)->toString(),
            ], 422);
        }
    }

    /**
     * @return array{found: int, marked_missing: int, removed: int, affected_jobs: int, queued_agent_syncs: int}
     */
    private function syncHosts(Request $request, SyncDockerVolumes $syncDockerVolumes): array
    {
        $scope = $this->resolveHostScope($request);
        $hosts = $scope['host_id'] !== null
            ? Host::query()->whereKey($scope['host_id'])->get()
            : Host::query()->whereIn('id', $scope['host_ids'])->active()->get();
        $result = [
            'found' => 0,
            'marked_missing' => 0,
            'removed' => 0,
            'affected_jobs' => 0,
            'queued_agent_syncs' => 0,
        ];

        foreach ($hosts as $host) {
            if ($host->type === Host::TYPE_LOCAL) {
                $localResult = $syncDockerVolumes->handle($host);
                $result['found'] += $localResult['found'];
                $result['marked_missing'] += $localResult['marked_missing'];
                $result['removed'] += $localResult['removed'];
                $result['affected_jobs'] += $localResult['affected_jobs'];

                continue;
            }

            AgentCommand::create([
                'host_id' => $host->id,
                'type' => AgentCommand::TYPE_SYNC_VOLUMES,
                'status' => AgentCommand::STATUS_PENDING,
                'payload' => [],
            ]);
            $result['queued_agent_syncs']++;
        }

        return $result;
    }

    private function serializeVolume(DockerVolume $volume): array
    {
        $data = $volume->toArray();
        unset($data['host']);

        return [
            ...$data,
            'host' => $this->safeHost($volume->host),
            'related_jobs_count' => BackupJob::where('host_id', $volume->host_id)
                ->where('volume_name', $volume->name)
                ->count(),
        ];
    }
}
