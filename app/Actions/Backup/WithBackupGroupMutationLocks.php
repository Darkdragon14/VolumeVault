<?php

namespace App\Actions\Backup;

use App\Models\BackupJobGroup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class WithBackupGroupMutationLocks
{
    private const LOCK_SECONDS = 30;

    private const WAIT_SECONDS = 5;

    public function handle(array $groupIds, callable $callback): mixed
    {
        $groupIds = collect($groupIds)
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($groupIds === []) {
            return $callback(collect());
        }

        return $this->withCacheLocks($groupIds, function () use ($groupIds, $callback): mixed {
            return DB::transaction(function () use ($groupIds, $callback): mixed {
                $groups = BackupJobGroup::query()
                    ->whereKey($groupIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                return $callback($groups);
            }, attempts: 3);
        });
    }

    private function withCacheLocks(array $groupIds, callable $callback): mixed
    {
        $groupId = array_shift($groupIds);

        if ($groupId === null) {
            return $callback();
        }

        return Cache::lock('backup-group-mutation-'.$groupId, self::LOCK_SECONDS)
            ->block(self::WAIT_SECONDS, fn (): mixed => $this->withCacheLocks($groupIds, $callback));
    }
}
