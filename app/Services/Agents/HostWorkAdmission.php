<?php

namespace App\Services\Agents;

use App\Models\BackupGroupRun;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Models\RestoreRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HostWorkAdmission
{
    public function isMaintained(int $hostId): bool
    {
        return DockerHost::query()->whereKey($hostId)->whereNotNull('maintenance_requested_at')->exists();
    }

    public function assertAccepting(int $hostId): void
    {
        if (! DockerHost::query()->whereKey($hostId)->whereNull('maintenance_requested_at')->exists()) {
            throw ValidationException::withMessages(['docker_host_id' => 'The Docker host is unavailable or maintenance has been requested.']);
        }
    }

    public function assertGroupAccepting(BackupJobGroup $group): void
    {
        foreach ($group->members()->pluck('docker_host_id')->unique() as $hostId) {
            $this->assertAccepting((int) $hostId);
        }
    }

    /**
     * Serialize admission with lifecycle setters locking the same DockerHost rows.
     * Standalone order: hosts (ascending ID), then run UPDATE. Group order: group,
     * member jobs, hosts (ascending ID), then run UPDATE, matching mutation locks.
     * Lifecycle setters must not acquire group/job locks while holding host locks.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function claim(BackupRun|RestoreRun|BackupGroupRun $run, array $attributes): int
    {
        return DB::transaction(function () use ($run, $attributes): int {
            $current = $run->newQuery()->find($run->getKey());

            if ($current === null || $current->status !== BackupRun::STATUS_QUEUED) {
                return 0;
            }

            $query = $current->newQuery()->whereKey($current->getKey())->where('status', BackupRun::STATUS_QUEUED);

            if ($current instanceof BackupGroupRun) {
                $group = $current->group()->lockForUpdate()->first();

                if ($group === null) {
                    return 0;
                }

                $hostIds = $group->members()->orderBy('id')->lockForUpdate()->get(['id', 'docker_host_id'])
                    ->pluck('docker_host_id')->merge($current->memberRuns()->pluck('docker_host_id'));
                $query->where('backup_job_group_id', $group->id);
            } else {
                $column = $current instanceof RestoreRun ? 'target_docker_host_id' : 'docker_host_id';
                $hostIds = collect([$current->getAttribute($column)]);
                $query->where($column, $current->getAttribute($column));
            }

            $hostIds = $hostIds->map(fn ($id): int => (int) $id)->unique()->sort()->values();

            foreach ($hostIds as $hostId) {
                $host = DockerHost::query()->lockForUpdate()->find($hostId);

                if ($host === null || $host->maintenance_requested_at !== null) {
                    return 0;
                }
            }

            if ($current instanceof BackupGroupRun) {
                $query->whereDoesntHave('group.members', fn (Builder $members) => $members->whereNotIn('docker_host_id', $hostIds))
                    ->whereDoesntHave('memberRuns', fn (Builder $members) => $members->whereNotIn('docker_host_id', $hostIds));
            }

            return $this->constrain($query)->update([...$attributes, 'status' => BackupRun::STATUS_RUNNING]);
        }, attempts: 3);
    }

    /** Apply the maintenance predicate to the UPDATE as defense in depth. */
    public function constrain(Builder $query): Builder
    {
        if ($query->getModel() instanceof BackupGroupRun) {
            return $query->whereHas('group')
                ->whereDoesntHave('group.members', fn (Builder $members) => $members
                    ->whereDoesntHave('dockerHost', fn (Builder $hosts) => $hosts->whereNull('maintenance_requested_at')))
                ->whereDoesntHave('memberRuns', fn (Builder $members) => $members
                    ->whereDoesntHave('dockerHost', fn (Builder $hosts) => $hosts->whereNull('maintenance_requested_at')));
        }

        $relation = $query->getModel() instanceof RestoreRun ? 'targetDockerHost' : 'dockerHost';

        return $query->whereHas($relation, fn (Builder $hosts) => $hosts->whereNull('maintenance_requested_at'));
    }

    /** Whether this run's hosts block new work (maintenance or missing host). */
    public function forRun(BackupRun|RestoreRun|BackupGroupRun $run): bool
    {
        return $this->constrain($run->newQuery()->whereKey($run->getKey()))->doesntExist();
    }

    /** Internal children belong to an already accepted operation and need crash recovery. */
    public function isWaiting(BackupRun|RestoreRun|BackupGroupRun $run): bool
    {
        if ($run->status !== BackupRun::STATUS_QUEUED) {
            return false;
        }

        if ($run instanceof BackupRun && ($run->belongsToGroupRun() || $run->trigger === BackupRun::TRIGGER_PRE_RESTORE)) {
            return false;
        }

        return $this->forRun($run);
    }
}
