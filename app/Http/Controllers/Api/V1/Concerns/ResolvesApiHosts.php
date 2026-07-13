<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Host;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait ResolvesApiHosts
{
    /**
     * @return array{host_id: int|null, host_ids: list<int>, all_hosts: bool}
     */
    protected function resolveHostScope(Request $request): array
    {
        $validated = $request->validate([
            'host_id' => ['nullable', 'integer', Rule::exists('hosts', 'id')],
            'all_hosts' => ['nullable', Rule::in(['true', 'false', '1', '0', true, false, 1, 0])],
        ]);

        $user = $request->user();
        $accessibleHostIds = $user?->accessibleHostIds() ?? [];

        if ($request->filled('host_id')) {
            $hostId = (int) $validated['host_id'];

            if (! in_array($hostId, $accessibleHostIds, true)) {
                throw new HttpException(403, 'You do not have access to this host.');
            }

            return [
                'host_id' => $hostId,
                'host_ids' => [$hostId],
                'all_hosts' => false,
            ];
        }

        if ($request->boolean('all_hosts')) {
            return [
                'host_id' => null,
                'host_ids' => $accessibleHostIds,
                'all_hosts' => true,
            ];
        }

        $localHostId = Host::localHost()->id;
        $canAccessLocalHost = in_array($localHostId, $accessibleHostIds, true);

        return [
            'host_id' => $canAccessLocalHost ? $localHostId : null,
            'host_ids' => $canAccessLocalHost ? [$localHostId] : [],
            'all_hosts' => false,
        ];
    }

    /**
     * @param  Builder<*>  $query
     * @param  array{host_id: int|null, host_ids: list<int>, all_hosts: bool}  $scope
     * @return Builder<*>
     */
    protected function applyHostScope(Builder $query, array $scope): Builder
    {
        if ($scope['host_id'] !== null) {
            $query->where('host_id', $scope['host_id']);

            return $query;
        }

        if ($scope['host_ids'] === []) {
            $query->whereRaw('1 = 0');

            return $query;
        }

        $query->whereIn('host_id', $scope['host_ids']);

        return $query;
    }

    protected function authorizeHostAccess(Request $request, ?int $hostId): void
    {
        if ($hostId === null || ! $request->user()?->canAccessHostId($hostId)) {
            throw new HttpException(403, 'You do not have access to this host.');
        }
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
