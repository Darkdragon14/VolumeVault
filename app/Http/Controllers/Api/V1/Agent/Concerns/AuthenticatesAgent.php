<?php

namespace App\Http\Controllers\Api\V1\Agent\Concerns;

use App\Models\Host;
use App\Services\Hosts\HostEnrollmentTokens;
use Illuminate\Http\Request;

trait AuthenticatesAgent
{
    protected function agentHost(Request $request): Host
    {
        $token = $request->bearerToken();
        $host = $token ? app(HostEnrollmentTokens::class)->resolve($token) : null;

        abort_unless($host && $host->type === Host::TYPE_AGENT && $host->is_active, 401);

        return $host;
    }
}
