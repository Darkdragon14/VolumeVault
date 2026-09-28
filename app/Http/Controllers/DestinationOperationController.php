<?php

namespace App\Http\Controllers;

use App\Http\Requests\DestinationOperationRequest;
use App\Models\AgentOperation;
use App\Models\BackupDestination;
use App\Services\BackupDestinations\DestinationOperations;
use Illuminate\Http\JsonResponse;

class DestinationOperationController extends Controller
{
    public function store(DestinationOperationRequest $request, BackupDestination $destination, DestinationOperations $operations): JsonResponse
    {
        $data = $request->validated();
        $operation = $operations->create($destination, $data['action'], isset($data['docker_host_id']) ? (int) $data['docker_host_id'] : null, $data['cursor'] ?? null, (int) ($data['limit'] ?? 1000), isset($data['backup_run_id']) ? (int) $data['backup_run_id'] : null);

        return response()->json(['data' => $operations->safe($operation)], 202);
    }

    public function show(BackupDestination $destination, AgentOperation $operation, DestinationOperations $operations): JsonResponse
    {
        abort_unless($operation->kind === 'destination' && (int) $operation->backup_destination_id === $destination->id, 404);

        return response()->json(['data' => $operations->safe($operation)]);
    }
}
