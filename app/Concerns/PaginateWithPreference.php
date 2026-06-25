<?php

namespace App\Concerns;

use Illuminate\Http\Request;

trait PaginateWithPreference
{
    private const VALID_PER_PAGE = [10, 20, 50, 100];

    private function perPageForRequest(Request $request): int
    {
        $userPerPage = $request->user()?->default_per_page ?? 10;
        $requested = $request->input('per_page');

        if ($requested === 'all' || $requested === '0' || $requested === 0) {
            return 0;
        }

        return in_array((int) $requested, self::VALID_PER_PAGE)
            ? (int) $requested
            : $userPerPage;
    }

    private function paginateForInertia(mixed $query, int $perPage, ?callable $map = null, string $pageName = 'page'): array
    {
        if ($perPage > 0) {
            $paginator = $query->paginate($perPage, ['*'], $pageName)->withQueryString();

            if ($map) {
                $paginator->through($map);
            }

            return [
                'data' => $paginator->items(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ];
        }

        $items = $query->get();

        if ($map) {
            $items = $items->map($map);
        }

        $items = $items->values();

        return [
            'data' => $items->all(),
            'meta' => [
                'current_page' => 1,
                'per_page' => 0,
                'total' => $items->count(),
                'last_page' => 1,
            ],
        ];
    }
}
