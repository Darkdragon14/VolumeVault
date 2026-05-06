<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Docker\SyncDockerVolumes;
use App\Http\Controllers\Controller;
use App\Models\BackupJob;
use App\Models\DockerVolume;
use Illuminate\Http\JsonResponse;
use Throwable;

class VolumeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => DockerVolume::query()
                ->orderByDesc('exists')
                ->orderBy('name')
                ->get()
                ->map(fn (DockerVolume $volume) => [
                    ...$volume->toArray(),
                    'related_jobs_count' => BackupJob::where('volume_name', $volume->name)->count(),
                ]),
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
