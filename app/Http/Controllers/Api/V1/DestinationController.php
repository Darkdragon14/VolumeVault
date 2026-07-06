<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Destinations\NormalizeDestinationData;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDestinationRequest;
use App\Http\Requests\UpdateDestinationRequest;
use App\Models\ActivityLog;
use App\Models\BackupDestination;
use App\Services\BackupDestinations\DestinationStorage;
use App\Services\BackupDestinations\TestBackupDestination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
        // Deleting a destination cascades its jobs and their runs, bypassing the
        // per-job delete guard — refuse while any is in flight or holds stopped
        // containers so reconciliation keeps the row it needs.
        if ($destination->hasRunInProgress()) {
            throw ValidationException::withMessages([
                'destination' => 'A backup or restore using this destination is in progress. Wait for it to finish before deleting it.',
            ]);
        }

        $destination->delete();

        return response()->json(status: 204);
    }

    public function test(BackupDestination $destination, TestBackupDestination $testBackupDestination): JsonResponse
    {
        $result = $testBackupDestination->handle($destination);

        return response()->json(['data' => $result], $result['ok'] ? 200 : 422);
    }

    public function hostKey(Request $request, DestinationStorage $storage): JsonResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ]);

        try {
            return response()->json(['data' => $storage->probeHostKey($data['host'], (int) ($data['port'] ?? 22))]);
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => str(trim($exception->getMessage()) ?: 'Unable to reach the SSH server.')->limit(300)->toString(),
            ], 422);
        }
    }
}
