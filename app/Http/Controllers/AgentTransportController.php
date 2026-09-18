<?php

namespace App\Http\Controllers;

use App\Http\Requests\AgentEnrollmentRequest;
use App\Http\Requests\AgentHeartbeatRequest;
use App\Http\Requests\AgentInventoryRequest;
use App\Http\Requests\AgentOperationResultRequest;
use App\Services\Agents\AgentOperationBroker;
use App\Services\Agents\AgentOperationEnvelope;
use App\Services\Agents\AgentRegistry;
use App\Services\Agents\ReceiveAgentInventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentTransportController extends Controller
{
    public function enroll(AgentEnrollmentRequest $request, AgentRegistry $registry): JsonResponse
    {
        $host = $registry->enroll((string) $request->bearerToken(), $request->validated());

        return response()->json([
            'host_uuid' => $host->uuid, 'protocol_version' => 1,
            'heartbeat_interval' => 30, 'inventory_interval' => 300,
        ]);
    }

    public function heartbeat(AgentHeartbeatRequest $request, AgentRegistry $registry): JsonResponse
    {
        $token = $registry->heartbeat($request->attributes->get('docker_host'), $request->validated());

        return response()->json(['acknowledged' => true, 'maintenance_token' => $token, 'operations_supported' => true]);
    }

    public function inventory(AgentInventoryRequest $request, ReceiveAgentInventory $receiveInventory): JsonResponse
    {
        return response()->json([
            'accepted' => $receiveInventory->handle($request->attributes->get('docker_host'), $request->validated()),
        ]);
    }

    public function pull(Request $request, AgentOperationBroker $broker): JsonResponse
    {
        $operation = $broker->pull($request->attributes->get('docker_host'));

        return response()->json(['operation' => $operation === null ? null : app(AgentOperationEnvelope::class)->seal($operation, (string) $request->bearerToken())]);
    }

    public function progress(Request $request, string $operation, AgentOperationBroker $broker): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/']]);
        $broker->progress($request->attributes->get('docker_host'), $operation, $data['token']);

        return response()->json(['acknowledged' => true]);
    }

    public function complete(AgentOperationResultRequest $request, string $operation, AgentOperationBroker $broker): JsonResponse
    {
        $data = $request->validated();
        $broker->complete($request->attributes->get('docker_host'), $operation, $data['token'], $data['result']);

        return response()->json(['acknowledged' => true]);
    }
}
