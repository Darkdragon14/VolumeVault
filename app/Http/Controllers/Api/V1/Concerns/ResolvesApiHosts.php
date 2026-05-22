<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Host;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

trait ResolvesApiHosts
{
    /**
     * @return array{host_id: int|null, all_hosts: bool}
     */
    protected function resolveHostScope(Request $request): array
    {
        $validated = $request->validate([
            'host_id' => ['nullable', 'integer', Rule::exists('hosts', 'id')],
            'all_hosts' => ['nullable', Rule::in(['true', 'false', '1', '0', true, false, 1, 0])],
        ]);

        if ($request->filled('host_id')) {
            return [
                'host_id' => (int) $validated['host_id'],
                'all_hosts' => false,
            ];
        }

        if ($request->boolean('all_hosts')) {
            return [
                'host_id' => null,
                'all_hosts' => true,
            ];
        }

        return [
            'host_id' => Host::localHost()->id,
            'all_hosts' => false,
        ];
    }

    /**
     * @param  Builder<*>  $query
     * @param  array{host_id: int|null, all_hosts: bool}  $scope
     * @return Builder<*>
     */
    protected function applyHostScope(Builder $query, array $scope): Builder
    {
        if ($scope['host_id'] !== null) {
            $query->where('host_id', $scope['host_id']);
        }

        return $query;
    }

    /**
     * @return array{id: int, name: string, type: string, status: string, is_active: bool, last_seen_at: mixed, agent_version: string|null, docker_version: string|null, capabilities: array<mixed>, metadata: array<mixed>}|null
     */
    protected function safeHost(?Host $host): ?array
    {
        if (! $host) {
            return null;
        }

        return [
            'id' => $host->id,
            'name' => $host->name,
            'type' => $host->type,
            'status' => $host->status,
            'is_active' => $host->is_active,
            'last_seen_at' => $host->last_seen_at,
            'agent_version' => $host->agent_version,
            'docker_version' => $host->docker_version,
            'capabilities' => $host->capabilities ?: [],
            'metadata' => $host->metadata ?: [],
        ];
    }
}
