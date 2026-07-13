<?php

namespace App\Services\Hosts;

use App\Models\Host;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class HostEnrollmentTokens
{
    public function issue(Host $host): string
    {
        $secret = Str::random(64);

        $host->forceFill([
            'enrollment_token_hash' => Hash::make($secret),
            'enrollment_token_expires_at' => now()->addHours((int) config('volumevault.hosts.enrollment_token_ttl_hours', 24)),
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

        if (! $host?->enrollment_token_hash) {
            return null;
        }

        if ($host->enrollment_token_expires_at && $host->enrollment_token_expires_at->isPast()) {
            return null;
        }

        if (! Hash::check($secret, $host->enrollment_token_hash)) {
            return null;
        }

        return $host;
    }
}
