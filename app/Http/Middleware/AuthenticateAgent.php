<?php

namespace App\Http\Middleware;

use App\Services\Agents\AgentRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAgent
{
    public function __construct(private readonly AgentRegistry $registry) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = $this->registry->authenticate((string) $request->bearerToken());
        abort_unless($host->agent_instance_id === $request->input('instance_id'), 401, 'Invalid agent instance.');
        $request->attributes->set('docker_host', $host);

        return $next($request);
    }
}
