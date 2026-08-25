<?php

namespace App\Actions\Backup;

use App\Models\BackupJob;
use Illuminate\Database\Eloquent\Builder;

class ApplyBackupJobSort
{
    /**
     * @param  Builder<BackupJob>  $query
     * @return Builder<BackupJob>
     */
    public function __invoke(Builder $query, mixed $sort, mixed $direction): Builder
    {
        $allowedSorts = ['created_at', 'name', 'next_run_at', 'last_run_at'];

        if ($sort === null) {
            $sort = 'created_at';
        } elseif (! is_string($sort) || ! in_array($sort, $allowedSorts, true)) {
            $sort = 'created_at';
            $direction = 'desc';
        }

        $direction = is_string($direction) && in_array($direction, ['asc', 'desc'], true) ? $direction : 'desc';

        if (in_array($sort, ['created_at', 'next_run_at', 'last_run_at'], true)) {
            return $query
                ->orderByRaw("CASE WHEN {$sort} IS NULL THEN 1 ELSE 0 END")
                ->orderBy($sort, $direction)
                ->orderBy('name')
                ->orderBy('id');
        }

        return $query->orderBy($sort, $direction)->orderBy('id');
    }
}
