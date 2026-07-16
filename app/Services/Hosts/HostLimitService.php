<?php

namespace App\Services\Hosts;

use App\Models\Host;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HostLimitService
{
    public function activeLimit(): int
    {
        return max(1, (int) config('volumevault.hosts.active_limit', 2));
    }

    public function activeCount(): int
    {
        return Host::query()->where('is_active', true)->count();
    }

    public function canActivate(?Host $host = null): bool
    {
        if ($host?->is_active) {
            return true;
        }

        return $this->activeCount() < $this->activeLimit();
    }

    public function ensureCanActivate(?Host $host = null): void
    {
        if ($this->canActivate($host)) {
            return;
        }

        throw ValidationException::withMessages([
            'host' => 'The active host limit has been reached.',
        ]);
    }

    public function withActivationSlot(Closure $callback, ?Host $host = null): mixed
    {
        return Cache::lock('volumevault:active-host-limit', 10)->block(5, function () use ($callback, $host): mixed {
            $host?->refresh();
            $this->ensureCanActivate($host);

            return DB::transaction($callback, 3);
        });
    }
}
