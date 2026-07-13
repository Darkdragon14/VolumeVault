<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Api\V1\Agent\Concerns\AuthenticatesAgent;
use App\Http\Controllers\Controller;
use App\Models\Host;
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
            'capabilities' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
            'last_error' => ['nullable', 'string', 'max:2000'],
        ]);

        $host->forceFill([
            'status' => filled($data['last_error'] ?? null) ? Host::STATUS_ERROR : Host::STATUS_ONLINE,
            'last_seen_at' => now(),
            'agent_version' => $data['agent_version'] ?? $host->agent_version,
            'docker_version' => $data['docker_version'] ?? $host->docker_version,
            'capabilities' => $data['capabilities'] ?? $host->capabilities,
            'metadata' => $data['metadata'] ?? $host->metadata,
            'last_error' => $data['last_error'] ?? null,
        ])->save();

        return response()->json(['data' => $host->safeForFrontend()]);
    }
}
