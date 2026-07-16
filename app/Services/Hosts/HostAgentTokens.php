<?php

namespace App\Services\Hosts;

use App\Models\Host;
use Illuminate\Support\Str;

class HostAgentTokens
{
    public function issue(Host $host): string
    {
        $secret = Str::random(64);

        $host->forceFill([
            'agent_token_hash' => hash('sha256', $secret),
        ])->save();

        return $host->id.'|'.$secret;
    }

    public function resolve(string $token): ?Host
    {
        [$hostId, $secret] = array_pad(explode('|', $token, 2), 2, null);

        if (! $hostId || ! $secret) {
            return null;
        }

        $host = Host::query()->find($hostId);

        if (! $host?->agent_token_hash || ! hash_equals($host->agent_token_hash, hash('sha256', $secret))) {
            return null;
        }

        return $host;
    }
}
