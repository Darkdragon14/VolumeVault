<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Controller;
use App\Models\Host;
use App\Services\Hosts\HostAgentTokens;
use App\Services\Hosts\HostEnrollmentTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnrollmentController extends Controller
{
    public function __invoke(Request $request, HostEnrollmentTokens $enrollmentTokens, HostAgentTokens $agentTokens): JsonResponse
    {
        $token = $request->bearerToken();
        $host = $token ? $enrollmentTokens->resolve($token) : null;
        abort_unless($host && $host->type === Host::TYPE_AGENT && $host->is_active, 401);

        $data = $request->validate([
            'agent_version' => ['nullable', 'string', 'max:255'],
            'docker_version' => ['nullable', 'string', 'max:255'],
            'capabilities' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ]);

        [$host, $agentToken] = DB::transaction(function () use ($host, $token, $data, $enrollmentTokens, $agentTokens): array {
            $host = Host::query()->lockForUpdate()->findOrFail($host->id);
            abort_unless($enrollmentTokens->resolve($token)?->is($host), 401);

            $host->forceFill([
                'status' => Host::STATUS_ONLINE,
                'last_seen_at' => now(),
                'agent_version' => $data['agent_version'] ?? $host->agent_version,
                'docker_version' => $data['docker_version'] ?? $host->docker_version,
                'capabilities' => $data['capabilities'] ?? $host->capabilities,
                'metadata' => $data['metadata'] ?? $host->metadata,
                'enrolled_at' => $host->enrolled_at ?: now(),
                'last_error' => null,
            ])->save();

            return [$host, $agentTokens->issue($host)];
        }, 3);

        return response()->json([
            'data' => $host->safeForFrontend(),
            'agent_token' => $agentToken,
        ]);
    }
}
