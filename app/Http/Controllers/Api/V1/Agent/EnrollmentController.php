<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Controller;
use App\Models\Host;
use App\Rules\BoundedJsonPayload;
use App\Services\Hosts\HostEnrollmentTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnrollmentController extends Controller
{
    public function __invoke(Request $request, HostEnrollmentTokens $enrollmentTokens): JsonResponse
    {
        $token = $request->bearerToken();
        $host = $token ? $enrollmentTokens->resolve($token) : null;
        abort_unless($host && $host->type === Host::TYPE_AGENT && $host->is_active, 401);

        $data = $request->validate([
            'agent_version' => ['nullable', 'string', 'max:255'],
            'docker_version' => ['nullable', 'string', 'max:255'],
            'capabilities' => ['nullable', 'array', 'max:100', new BoundedJsonPayload],
            'metadata' => ['nullable', 'array', 'max:100', new BoundedJsonPayload],
            'enrollment_request_id' => ['required', 'uuid'],
            'agent_secret' => ['required', 'string', 'size:64'],
        ]);

        [$host, $agentToken] = DB::transaction(function () use ($host, $token, $data, $enrollmentTokens): array {
            $host = Host::query()->lockForUpdate()->findOrFail($host->id);
            abort_unless($enrollmentTokens->resolve($token)?->is($host), 401);
            $agentTokenHash = hash('sha256', $data['agent_secret']);

            if ($host->enrollment_token_consumed_at !== null) {
                abort_unless(
                    $host->enrollment_request_id === $data['enrollment_request_id']
                    && hash_equals((string) $host->agent_token_hash, $agentTokenHash),
                    401,
                );

                return [$host, $host->id.'|'.$data['agent_secret']];
            }

            $host->forceFill([
                'status' => $host->last_error ? Host::STATUS_ERROR : Host::STATUS_ONLINE,
                'last_seen_at' => now(),
                'agent_version' => $data['agent_version'] ?? $host->agent_version,
                'docker_version' => $data['docker_version'] ?? $host->docker_version,
                'capabilities' => $data['capabilities'] ?? $host->capabilities,
                'metadata' => $data['metadata'] ?? $host->metadata,
                'enrollment_request_id' => $data['enrollment_request_id'],
                'enrollment_token_consumed_at' => now(),
                'agent_token_hash' => $agentTokenHash,
                'enrolled_at' => $host->enrolled_at ?: now(),
            ])->save();

            return [$host, $host->id.'|'.$data['agent_secret']];
        }, 3);

        return response()->json([
            'data' => $host->safeForFrontend(),
            'agent_token' => $agentToken,
        ]);
    }
}
