<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Destinations\DestinationMutationBlocked;
use App\Actions\Destinations\MutateDestination;
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
            'data' => BackupDestination::latest()->get()->map(fn (BackupDestination $destination): array => [...$destination->safeForFrontend(), 'docker_host_id' => $destination->docker_host_id]),
        ]);
    }

    public function store(StoreDestinationRequest $request, MutateDestination $mutateDestination): JsonResponse
    {
        $destination = $mutateDestination->create([
            ...$request->validated(),
            'use_path_style_endpoint' => $request->boolean('use_path_style_endpoint'),
            'is_active' => $request->boolean('is_active', true),
        ]);

        ActivityLog::record('backup_destination_created', 'Backup destination created via API.', $destination, [
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => [...$destination->safeForFrontend(), 'docker_host_id' => $destination->docker_host_id]], 201);
    }

    public function show(BackupDestination $destination): JsonResponse
    {
        return response()->json(['data' => [...$destination->safeForFrontend(), 'docker_host_id' => $destination->docker_host_id]]);
    }

    public function update(UpdateDestinationRequest $request, BackupDestination $destination, MutateDestination $mutateDestination): JsonResponse
    {
        $data = $request->validated();

        foreach (['use_path_style_endpoint', 'is_active'] as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->boolean($field);
            }
        }

        try {
            $mutateDestination->update($destination, $data);
        } catch (DestinationMutationBlocked $exception) {
            throw ValidationException::withMessages(['is_active' => $exception->getMessage()]);
        }

        $destination->refresh();

        return response()->json(['data' => [...$destination->safeForFrontend(), 'docker_host_id' => $destination->docker_host_id]]);
    }

    public function destroy(BackupDestination $destination, MutateDestination $mutateDestination): JsonResponse
    {
        try {
            $mutateDestination->delete($destination);
        } catch (DestinationMutationBlocked $exception) {
            throw ValidationException::withMessages(['destination' => $exception->getMessage()]);
        }

        return response()->json(status: 204);
    }

    public function test(Request $request, BackupDestination $destination, TestBackupDestination $testBackupDestination): JsonResponse
    {
        $data = $request->validate(['docker_host_id' => ['nullable', 'integer', 'exists:docker_hosts,id']]);
        $operations = app(\App\Services\BackupDestinations\DestinationOperations::class);
        $hostId = $operations->hostId($destination, isset($data['docker_host_id']) ? (int) $data['docker_host_id'] : null);
        if ($hostId !== \App\Models\DockerHost::LOCAL_ID) {
            return response()->json(['data' => $operations->safe($operations->create($destination, 'test', $hostId))], 202);
        }
        $result = $testBackupDestination->handle($destination);

        return response()->json(['data' => $result], $result['ok'] ? 200 : 422);
    }

    public function hostKeyOperation(\App\Models\AgentOperation $operation): JsonResponse
    {
        abort_unless($operation->kind === 'destination' && $operation->destination_action === 'host_key' && $operation->backup_destination_id === null, 404);

        return response()->json(['data' => app(\App\Services\BackupDestinations\DestinationOperations::class)->safe($operation)]);
    }

    public function hostKey(Request $request, DestinationStorage $storage): JsonResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'docker_host_id' => ['nullable', 'integer', 'exists:docker_hosts,id'],
        ]);

        if (isset($data['docker_host_id']) && (int) $data['docker_host_id'] !== \App\Models\DockerHost::LOCAL_ID) {
            $operations = app(\App\Services\BackupDestinations\DestinationOperations::class);

            return response()->json(['data' => $operations->safe($operations->createHostKey($data['host'], (int) ($data['port'] ?? 22), (int) $data['docker_host_id']))], 202);
        }

        try {
            return response()->json(['data' => $storage->probeHostKey($data['host'], (int) ($data['port'] ?? 22))]);
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => str(trim($exception->getMessage()) ?: 'Unable to reach the SSH server.')->limit(300)->toString(),
            ], 422);
        }
    }
}
