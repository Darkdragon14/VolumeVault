<?php

namespace App\Services\Agents;

use App\Models\DockerHost;
use Illuminate\Http\Request;

class EnvironmentSelection
{
    public function hostId(Request $request): ?int
    {
        if (! $this->isWebSession($request)) {
            return null;
        }

        $id = $request->session()->get($this->key($request));

        return is_int($id) && DockerHost::whereKey($id)->exists() ? $id : null;
    }

    public function select(Request $request, ?int $hostId): void
    {
        if ($this->isWebSession($request)) {
            $request->session()->put($this->key($request), $hostId);
        }
    }

    public function isWebSession(Request $request): bool
    {
        return ! $request->is('api/*') && $request->hasSession() && $request->user() !== null;
    }

    private function key(Request $request): string
    {
        return 'environment.'.$request->user()->getAuthIdentifier();
    }
}
