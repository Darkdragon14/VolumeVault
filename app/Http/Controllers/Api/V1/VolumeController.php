<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Docker\SyncDockerVolumes;
use App\Http\Controllers\Controller;
use App\Models\DockerHost;
use App\Models\DockerVolume;
use App\Services\Volumes\VolumeBackupSummaries;
use App\Support\DeploymentMode;
use Illuminate\Http\JsonResponse;
use Throwable;

class VolumeController extends Controller
{
    public function index(VolumeBackupSummaries $volumeBackupSummaries): JsonResponse
    {
        $volumes = DockerVolume::query()
            ->when(DeploymentMode::isOrchestrator(), fn ($query) => $query->whereRaw('1 = 0'))
            ->where('docker_host_id', DockerHost::LOCAL_ID)
            ->orderByDesc('exists')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $volumeBackupSummaries->forVolumes($volumes),
        ]);
    }

    public function sync(SyncDockerVolumes $syncDockerVolumes): JsonResponse
    {
        try {
            return response()->json(['data' => $syncDockerVolumes->handle()]);
        } catch (Throwable $exception) {
            return response()->json([
                'message' => 'Unable to sync Docker volumes.',
                'error' => str($exception->getMessage())->limit(500)->toString(),
            ], 422);
        }
    }
}
