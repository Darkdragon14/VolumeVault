<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Destinations\NormalizeDestinationData;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDestinationRequest;
use App\Http\Requests\UpdateDestinationRequest;
use App\Models\ActivityLog;
use App\Models\BackupDestination;
use App\Services\BackupDestinations\TestBackupDestination;
use Illuminate\Http\JsonResponse;

class DestinationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => BackupDestination::latest()->get()->map->safeForFrontend(),
        ]);
    }

    public function store(StoreDestinationRequest $request, NormalizeDestinationData $normalizeDestinationData): JsonResponse
    {
        $data = $normalizeDestinationData->handle([
            ...$request->validated(),
            'use_path_style_endpoint' => $request->boolean('use_path_style_endpoint'),
            'is_active' => $request->boolean('is_active', true),
        ]);

        $destination = BackupDestination::create($data);

        ActivityLog::record('backup_destination_created', 'Backup destination created via API.', $destination, [
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $destination->safeForFrontend()], 201);
    }

    public function show(BackupDestination $destination): JsonResponse
    {
        return response()->json(['data' => $destination->safeForFrontend()]);
    }

    public function update(UpdateDestinationRequest $request, BackupDestination $destination, NormalizeDestinationData $normalizeDestinationData): JsonResponse
    {
        $data = $normalizeDestinationData->handle([
            ...$request->validated(),
            'use_path_style_endpoint' => $request->boolean('use_path_style_endpoint'),
            'is_active' => $request->boolean('is_active'),
        ], $destination);

        $destination->update($data);

        return response()->json(['data' => $destination->fresh()->safeForFrontend()]);
    }

    public function destroy(BackupDestination $destination): JsonResponse
    {
        $destination->delete();

        return response()->json(status: 204);
    }

    public function test(BackupDestination $destination, TestBackupDestination $testBackupDestination): JsonResponse
    {
        $result = $testBackupDestination->handle($destination);

        return response()->json(['data' => $result], $result['ok'] ? 200 : 422);
    }
}
