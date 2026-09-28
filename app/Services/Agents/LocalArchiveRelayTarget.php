<?php

namespace App\Services\Agents;

use App\Actions\Docker\ContainerIsAlive;
use App\Actions\Docker\RemoveDockerContainer;
use App\Actions\Restore\RunRestore;
use App\Jobs\RunRestoreJob;
use App\Models\AgentOperation;
use App\Models\ArchiveRelay;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Models\RestoreRun;
use App\Support\VolumeJobLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class LocalArchiveRelayTarget
{
    /** Host, run, relay, operation: the same lock order as target expiry. */
    public function admit(ArchiveRelay $relay): ?AgentOperation
    {
        $acquiredLock = null;
        try {
            return DB::transaction(function () use ($relay, &$acquiredLock): ?AgentOperation {
                $host = DockerHost::query()->lockForUpdate()->findOrFail($relay->target_docker_host_id);
                $run = RestoreRun::query()->lockForUpdate()->findOrFail($relay->restore_run_id);
                $relay = ArchiveRelay::query()->lockForUpdate()->findOrFail($relay->id);
                $operation = AgentOperation::where('restore_run_id', $run->id)->lockForUpdate()->first();
                abort_unless($host->isLocal() && $run->target_docker_host_id === $host->id && $run->mode === RestoreRun::MODE_NEW_VOLUME, 409);
                if ($operation !== null) {
                    if ($run->status !== 'running' || $operation->status !== 'running'
                        || ($operation->context['local_relay_phase'] ?? null) !== 'downloading') {
                        return null;
                    }
                } elseif ($host->maintenance_requested_at !== null || $run->status !== 'queued' || $relay->status !== 'ready'
                    || $relay->expires_at->lessThanOrEqualTo(now()) || $relay->cleaned_at !== null
                    || $relay->sourceAgentOperation->status !== 'completed') {
                    return null;
                }
                $owner = $operation?->context['volume_lock_owner'] ?? (string) Str::uuid();
                $volumeLock = Cache::lock(VolumeJobLock::cacheKey($run->target_volume_name), 86400, $owner);
                $alreadyOwned = $volumeLock->isOwnedByCurrentProcess();
                $acquired = $volumeLock->get();
                if (! $acquired && ! $volumeLock->isOwnedByCurrentProcess()) {
                    return null;
                }
                if ($acquired && ! $alreadyOwned) {
                    $acquiredLock = $volumeLock;
                }
                if ($this->volumeBusy($run, $operation)) {
                    $acquiredLock?->release();
                    $acquiredLock = null;

                    return null;
                }
                if ($operation !== null) {
                    return $operation;
                }
                $operation = AgentOperation::create([
                    'id' => (string) Str::uuid(), 'docker_host_id' => $host->id, 'restore_run_id' => $run->id,
                    'kind' => 'restore', 'status' => 'running', 'claimed_at' => now(), 'last_progress_at' => now(),
                    'context' => ['archive_relay_id' => $relay->id, 'local_relay_phase' => 'downloading', 'volume_lock_owner' => $owner],
                ]);
                $run->forceFill(['status' => 'running', 'started_at' => now(), 'last_heartbeat_at' => now()])->save();
                $relay->update(['status' => 'downloading']);

                return $operation;
            });
        } catch (Throwable $exception) {
            $acquiredLock?->release();

            throw $exception;
        }
    }

    public function handle(RestoreRun $run, callable $execute): void
    {
        $relay = $run->archiveRelay;
        $worker = $this->workerLock($relay);
        if ($worker === null) {
            return;
        }
        try {
            $operation = $this->admit($relay);
            if ($operation === null || $operation->context['local_relay_phase'] !== 'downloading') {
                return;
            }
            try {
                $archive = app(ArchiveRelays::class)->downloadLocal($relay->fresh());
                DB::transaction(function () use ($run, $relay, $operation): void {
                    DockerHost::query()->lockForUpdate()->findOrFail($run->target_docker_host_id);
                    $run = RestoreRun::query()->lockForUpdate()->findOrFail($run->id);
                    $relay = ArchiveRelay::query()->lockForUpdate()->findOrFail($relay->id);
                    $operation = AgentOperation::query()->lockForUpdate()->findOrFail($operation->id);
                    abort_unless($run->status === 'running' && $operation->status === 'running'
                        && $operation->context['local_relay_phase'] === 'downloading', 409);
                    abort_unless(Cache::restoreLock(VolumeJobLock::cacheKey($run->target_volume_name), $operation->context['volume_lock_owner'])->isOwnedByCurrentProcess(), 409);
                    $operation->update(['context' => [...$operation->context, 'local_relay_phase' => 'executing']]);
                    $relay->update(['status' => 'restoring']);
                });
                $execute($archive);
            } catch (Throwable) {
                app(RunRestore::class)->markFailed($run->fresh(), new RuntimeException('Local archive relay download or execution failed.'), RestoreRun::STATUS_RUNNING);
            }
        } finally {
            fclose($worker);
        }
    }

    /** A dead worker may resume downloads, but never replay target execution. */
    public function recover(ArchiveRelay $relay): void
    {
        $worker = $this->workerLock($relay);
        if ($worker === null) {
            return;
        }
        try {
            $relay->refresh();
            $run = $relay->restoreRun;
            $operation = AgentOperation::where('restore_run_id', $run->id)->firstOrFail();
            if ($relay->cleaned_at !== null) {
                $this->releaseVolumeLock($run, $operation);

                return;
            }
            if ($run->status === 'running' && $operation->context['local_relay_phase'] === 'downloading') {
                RunRestoreJob::dispatch($run->id);

                return;
            }
            if ($run->docker_container_id) {
                if (app(ContainerIsAlive::class)->handle($run->docker_container_id) !== false) {
                    return;
                }
                app(RemoveDockerContainer::class)->handle($run->docker_container_id);
                $run->update(['docker_container_id' => null]);
            }
            if ($run->stopped_container_ids) {
                return;
            }
            if ($run->status === 'running') {
                app(RunRestore::class)->markFailed($run, new RuntimeException('Local relay worker was interrupted after restore execution was admitted; execution was not replayed.'), RestoreRun::STATUS_RUNNING);
                $run->refresh();
            }
            if (! in_array($run->status, ['success', 'failed', 'cancelled'], true)) {
                return;
            }
            $relay->update(['status' => 'cleanup']);
            $runtimeArchive = storage_path('app/restore-runs/'.$run->id.'/backup.tar.gz');
            if (is_link(dirname($runtimeArchive)) || is_link($runtimeArchive)
                || (file_exists($runtimeArchive) && ! File::delete($runtimeArchive))) {
                return;
            }
            $directory = app(ArchiveRelayStorage::class)->directory($relay->id);
            if (is_link($directory)) {
                return;
            }
            if (is_dir($directory) && ! File::deleteDirectory($directory)) {
                return;
            }
            $this->releaseVolumeLock($run, $operation);
            DB::transaction(function () use ($run, $relay, $operation): void {
                DockerHost::query()->lockForUpdate()->findOrFail($run->target_docker_host_id);
                RestoreRun::query()->lockForUpdate()->findOrFail($run->id);
                $relay = ArchiveRelay::query()->lockForUpdate()->findOrFail($relay->id);
                $operation = AgentOperation::query()->lockForUpdate()->findOrFail($operation->id);
                $relay->update(['cleaned_at' => now(), 'status' => $run->status === 'success' ? 'completed' : 'failed']);
                $operation->update(['status' => 'completed', 'completed_at' => now()]);
            });
        } finally {
            fclose($worker);
        }
    }

    /** Stable lock files live outside removable spool directories and survive process restarts. */
    public function workerLock(ArchiveRelay $relay): mixed
    {
        $storage = app(ArchiveRelayStorage::class);
        $directory = dirname($storage->directory($relay->id)).'/target-locks';
        $storage->secureDirectory($directory);
        $path = $directory.'/'.$relay->id.'.lock';
        if (is_link($path)) {
            throw new RuntimeException('Unsafe local relay worker lock.');
        }
        $mask = umask(0077);
        $lock = fopen($path, 'c');
        umask($mask);
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return null;
        }

        return $lock;
    }

    private function releaseVolumeLock(RestoreRun $run, AgentOperation $operation): void
    {
        Cache::restoreLock(VolumeJobLock::cacheKey($run->target_volume_name), $operation->context['volume_lock_owner'])->release();
    }

    /** Rechecked under the host lock; a pre-admission queue check is only a hint. */
    private function volumeBusy(RestoreRun $run, ?AgentOperation $resuming): bool
    {
        $restores = RestoreRun::where('target_docker_host_id', $run->target_docker_host_id)
            ->where('target_volume_name', $run->target_volume_name)->whereKeyNot($run->id)
            ->where(function ($query): void {
                $query->where('status', 'running')
                    ->orWhere('docker_container_cleanup_pending', true)
                    ->orWhere(fn ($query) => $query->whereNotNull('stopped_container_ids')->whereJsonLength('stopped_container_ids', '>', 0))
                    ->orWhereHas('archiveRelay', fn ($query) => $query->whereNull('cleaned_at')->whereIn('status', ['downloading', 'restoring', 'cleanup']));
            })->get();
        foreach ($restores as $other) {
            if ($resuming !== null && $other->status === 'running' && ! $other->docker_container_id && ! $other->stopped_container_ids) {
                $waiter = AgentOperation::where('restore_run_id', $other->id)->where('docker_host_id', $run->target_docker_host_id)->where('kind', 'restore')->where('status', 'running')->first();
                if (($waiter?->context['local_relay_phase'] ?? null) === 'downloading'
                    && $other->archiveRelay !== null
                    && ($waiter->context['archive_relay_id'] ?? null) === $other->archiveRelay?->id
                    && ($waiter->context['volume_lock_owner'] ?? null) !== $resuming->context['volume_lock_owner']) {
                    // Older code could admit a waiter without owning the volume. The
                    // current lock owner may recover; the waiter cannot start extraction.
                    continue;
                }
            }

            return true;
        }

        return BackupRun::where('docker_host_id', $run->target_docker_host_id)
            ->where(fn ($query) => $query->where('source_volume_name', $run->target_volume_name)
                ->orWhere(fn ($query) => $query->whereNull('source_type_snapshot')->whereHas('job', fn ($query) => $query->where('volume_name', $run->target_volume_name))))
            ->where(fn ($query) => $query->where('status', 'running')->orWhere('docker_container_cleanup_pending', true)
                ->orWhere(fn ($query) => $query->whereNotNull('stopped_container_ids')->whereJsonLength('stopped_container_ids', '>', 0)))
            ->exists();
    }
}
