<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Docker\SyncDockerVolumes;
use App\Http\Controllers\Controller;
use App\Jobs\SyncDockerVolumesJob;
use App\Models\DockerHost;
use App\Models\DockerVolume;
use App\Services\Agents\HostWorkAdmission;
use App\Services\Agents\OperationalHostScope;
use App\Services\Docker\LocalDockerExecution;
use App\Services\Volumes\VolumeBackupSummaries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class VolumeController extends Controller
{
    public function index(VolumeBackupSummaries $volumeBackupSummaries, OperationalHostScope $scope): JsonResponse
    {
        $volumes = $scope->query(DockerVolume::class)
            ->orderByDesc('exists')
            ->orderBy('name')
            ->get();

        return response()->json([
            ...$scope->props(),
            'data' => $volumeBackupSummaries->forVolumes($volumes, $scope),
        ]);
    }

    public function sync(Request $request, SyncDockerVolumes $syncDockerVolumes): JsonResponse
    {
        $request->validate(['docker_host_id' => ['required_if:async,true', 'integer', 'in:1'], 'async' => ['sometimes', 'boolean']]);
        LocalDockerExecution::validate();
        app(HostWorkAdmission::class)->assertAccepting(DockerHost::LOCAL_ID);
        try {
            if ($request->boolean('async')) {
                SyncDockerVolumesJob::dispatch();

                return response()->json(['data' => ['docker_host_id' => 1, 'queued' => true]], 202);
            }

            return response()->json(['data' => $syncDockerVolumes->handle()]);
        } catch (Throwable $exception) {
            return response()->json([
                'message' => 'Unable to sync Docker volumes.',
                'error' => str($exception->getMessage())->limit(500)->toString(),
            ], 422);
        }
    }
}
