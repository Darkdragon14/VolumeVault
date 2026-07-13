<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Host;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

trait AuthorizesHostAccess
{
    /**
     * @return list<int>
     */
    protected function accessibleHostIds(Request $request): array
    {
        return $request->user()?->accessibleHostIds() ?? [];
    }

    /**
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    protected function applyAccessibleHosts(Builder $query, Request $request): Builder
    {
        $hostIds = $this->accessibleHostIds($request);

        if ($hostIds === []) {
            $query->whereRaw('1 = 0');

            return $query;
        }

        $query->whereIn('host_id', $hostIds);

        return $query;
    }

    /**
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    protected function applyHostFilter(Builder $query, Request $request): Builder
    {
        $hostId = $this->selectedHostId($request);

        if ($hostId !== null) {
            $query->where('host_id', $hostId);

            return $query;
        }

        return $this->applyAccessibleHosts($query, $request);
    }

    protected function selectedHostId(Request $request): ?int
    {
        if (! $request->filled('host_id')) {
            return null;
        }

        $hostId = $request->integer('host_id');
        $this->authorizeHostAccess($request, $hostId);

        return $hostId;
    }

    /**
     * @return list<array{id: int, name: string, type: string, status: string, is_active: bool}>
     */
    protected function accessibleHostsForFrontend(Request $request): array
    {
        $hostIds = $this->accessibleHostIds($request);

        if ($hostIds === []) {
            return [];
        }

        return Host::query()
            ->whereIn('id', $hostIds)
            ->orderByRaw("case when type = 'local' then 0 else 1 end")
            ->orderBy('name')
            ->get()
            ->map(fn (Host $host) => [
                'id' => $host->id,
                'name' => $host->name,
                'type' => $host->type,
                'status' => $host->status,
                'is_active' => $host->is_active,
            ])
            ->all();
    }

    protected function authorizeHostAccess(Request $request, ?int $hostId): void
    {
        abort_unless($hostId !== null && $request->user()?->canAccessHostId($hostId), 403);
    }
}
