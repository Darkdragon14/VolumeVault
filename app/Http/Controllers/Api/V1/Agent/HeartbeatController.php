<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Api\V1\Agent\Concerns\AuthenticatesAgent;
use App\Http\Controllers\Controller;
use App\Models\Host;
use App\Rules\BoundedJsonPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HeartbeatController extends Controller
{
    use AuthenticatesAgent;

    public function __invoke(Request $request): JsonResponse
    {
        $host = $this->agentHost($request);

        $data = $request->validate([
            'agent_version' => ['nullable', 'string', 'max:255'],
            'docker_version' => ['nullable', 'string', 'max:255'],
            'capabilities' => ['nullable', 'array', 'max:100', new BoundedJsonPayload],
            'metadata' => ['nullable', 'array', 'max:100', new BoundedJsonPayload],
            'last_error' => ['nullable', 'string', 'max:2000'],
        ]);

        $lastError = filled($data['last_error'] ?? null) ? $data['last_error'] : $host->last_error;

        $host->forceFill([
            'status' => $lastError ? Host::STATUS_ERROR : Host::STATUS_ONLINE,
            'last_seen_at' => now(),
            'agent_version' => $data['agent_version'] ?? $host->agent_version,
            'docker_version' => $data['docker_version'] ?? $host->docker_version,
            'capabilities' => $data['capabilities'] ?? $host->capabilities,
            'metadata' => $data['metadata'] ?? $host->metadata,
            'last_error' => $lastError,
        ])->save();

        return response()->json(['data' => $host->safeForFrontend()]);
    }
}
