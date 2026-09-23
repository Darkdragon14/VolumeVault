<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAgentTransport
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('volumevault.agents.enabled'), 404);
        abort_unless($request->isSecure(), 426, 'Agent transport requires HTTPS.');
        abort_unless($request->isJson(), 415, 'Agent requests require JSON.');
        abort_if(strlen($request->getContent()) > 2 * 1024 * 1024, 413);

        return $next($request)->header('Cache-Control', 'no-store');
    }
}
