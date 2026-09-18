<?php

namespace App\Http\Middleware;

use App\Services\Agents\AgentCompatibility;
use App\Services\Agents\AgentRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAgentProtocol
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->input('protocol_version') !== AgentCompatibility::PROTOCOL_VERSION) {
            if ($host = $request->attributes->get('docker_host')) {
                app(AgentRegistry::class)->recordIncompatibleProtocol($host, $request->input('protocol_version'), $request->input('version'));
            }

            return response()->json(['message' => 'Unsupported agent protocol version.', 'supported_protocols' => [AgentCompatibility::PROTOCOL_VERSION]], 409);
        }

        return $next($request);
    }
}
