<?php

namespace App\Services\Agents;

use App\Actions\Docker\ContainerIsAlive;
use App\Actions\Docker\RemoveDockerContainer;
use App\Actions\Restore\RunRestore;
use App\Jobs\ExportLocalArchiveRelay;
use App\Models\AgentOperation;
use App\Models\ArchiveRelay;
use App\Models\BackupDestination;
use App\Models\DockerHost;
use App\Models\RestoreRun;
use App\Services\Logging\AppendRunLog;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ArchiveRelays
{
    public function required(BackupDestination $destination, int $targetHostId): bool
    {
        return $destination->isHostBound() && (int) $destination->docker_host_id !== $targetHostId;
    }

    public function validate(BackupDestination $destination, int $targetHostId, string $mode): void
    {
        if ($mode !== RestoreRun::MODE_NEW_VOLUME) {
            throw ValidationException::withMessages(['mode' => 'Cross-host archive transfers require restore to a new volume.']);
        }
        foreach ([$destination->docker_host_id, $targetHostId] as $id) {
            try {
                app(AgentExecution::class)->validateHost((int) $id, 'archive-relay-v1');
            } catch (ValidationException) {
                throw ValidationException::withMessages(['target_docker_host_id' => 'Archive transfer requires available source and target hosts supporting archive-relay-v1.']);
            }
            app(HostWorkAdmission::class)->assertAccepting((int) $id);
        }
    }

    public function create(RestoreRun $run, BackupDestination $destination, ?int $backupRunId): ArchiveRelay
    {
        DockerHost::query()->whereKey($destination->docker_host_id)->lockForUpdate()->firstOrFail();
        $this->validate($destination, $run->target_docker_host_id, $run->mode);
        DockerHost::query()->whereKey(DockerHost::LOCAL_ID)->lockForUpdate()->firstOrFail();
        $max = (int) config('volumevault.archive_relay.max_bytes');
        $limit = (int) config('volumevault.archive_relay.max_disk_bytes');
        $reserved = (int) ArchiveRelay::whereNull('cleaned_at')->sum('reserved_bytes');
        if ($max <= 0 || $limit < 3 * ($reserved + $max)) {
            throw ValidationException::withMessages(['destination' => 'Archive relay disk quota is exhausted.']);
        }
        $relay = ArchiveRelay::create([
            'id' => (string) Str::uuid(), 'restore_run_id' => $run->id, 'backup_run_id' => $backupRunId,
            'source_docker_host_id' => $destination->docker_host_id, 'target_docker_host_id' => $run->target_docker_host_id,
            'destination_snapshot' => app(DispatchAgentOperation::class)->destination($destination),
            'source_key' => $run->selected_backup_key, 'reserved_bytes' => $max,
            'expires_at' => now()->addSeconds(max(60, (int) config('volumevault.archive_relay.ttl_seconds'))),
        ]);
        $operation = AgentOperation::create([
            'id' => (string) Str::uuid(), 'docker_host_id' => $destination->docker_host_id,
            'kind' => 'archive_export', 'status' => 'pending',
            'payload' => ['version' => 1, 'destination' => $relay->destination_snapshot,
                'relay' => ['id' => $relay->id, 'key' => $relay->source_key, 'max_bytes' => $max]],
        ]);
        $relay->update(['source_agent_operation_id' => $operation->id]);
        app(AgentOperationSpecification::class)->validate(['id' => $operation->id, 'token' => str_repeat('0', 64), 'kind' => 'archive_export', 'spec' => $operation->payload]);

        return $relay;
    }

    public function transfer(DockerHost $host, string $id, string $token, array $data): array
    {
        return DB::transaction(function () use ($host, $id, $token, $data): array {
            $locked = DockerHost::query()->lockForUpdate()->find($host->id);
            app(AgentRegistry::class)->assertCredential($locked, $host->agent_token_hash, $host->agent_instance_id);
            $operation = AgentOperation::query()->where('docker_host_id', $host->id)->lockForUpdate()->find($id);
            abort_unless($operation && $operation->owner_instance_id === $locked->agent_instance_id
                && is_string($operation->delivery_token) && hash_equals($operation->delivery_token, $token), 404);
            abort_unless($operation->status === 'running', 409, 'Operation is not active.');
            $relay = $operation->kind === 'archive_export'
                ? ArchiveRelay::where('source_agent_operation_id', $id)->lockForUpdate()->firstOrFail()
                : ArchiveRelay::where('restore_run_id', $operation->restore_run_id)->lockForUpdate()->firstOrFail();
            $storage = app(ArchiveRelayStorage::class);
            $operation->update(['last_progress_at' => now()]);
            if ($operation->kind === 'archive_export') {
                abort_unless($data['action'] === 'upload', 422);
                $chunk = base64_decode($data['chunk'] ?? '', true);
                abort_unless(is_string($chunk), 422, 'Invalid chunk encoding.');
                $offset = $storage->upload($relay, $data['offset'], $chunk, $data['size_bytes'], $data['sha256']);

                return ['offset' => $offset];
            }
            abort_unless($operation->kind === 'restore' && in_array($data['action'], ['download', 'verified'], true)
                && in_array($relay->status, ['ready', 'downloading', 'restoring'], true), 409);
            $offset = $data['offset'];
            if ($data['action'] === 'verified') {
                abort_unless($offset === $relay->size_bytes && $relay->downloaded_bytes === $relay->size_bytes, 409);
                $relay->update(['status' => 'restoring']);

                return ['acknowledged' => true];
            }
            abort_unless($offset <= $relay->downloaded_bytes && $offset < $relay->size_bytes, 409, 'Unexpected download offset.');
            $chunk = $storage->read($relay, $offset);
            $relay->update(['status' => 'downloading', 'downloaded_bytes' => max($relay->downloaded_bytes, $offset + strlen($chunk))]);

            return ['offset' => $offset, 'chunk' => base64_encode($chunk), 'size_bytes' => $relay->size_bytes, 'sha256' => $relay->sha256];
        }, attempts: 3);
    }

    public function verifyExport(AgentOperation $operation, array $result): array
    {
        if ($result['status'] !== 'success') {
            return $result;
        }
        $relay = ArchiveRelay::where('source_agent_operation_id', $operation->id)->firstOrFail();
        $storage = app(ArchiveRelayStorage::class);
        $directory = $storage->directory($relay->id);
        $storage->secureDirectory($directory);
        if (is_link($directory.'/verify.lock')) {
            throw new RuntimeException('Unsafe archive verification lock.');
        }
        $lock = fopen($directory.'/verify.lock', 'c');
        chmod($directory.'/verify.lock', 0600);
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            abort(503, 'Archive verification is already in progress.');
        }
        $previousAbort = ignore_user_abort(true);
        $previousLimit = (int) ini_get('max_execution_time');
        set_time_limit(0);
        try {
            $storage->verify($relay);
            $result['_verified_relay'] = ['id' => $relay->id, 'size' => $relay->size_bytes, 'sha256' => $relay->sha256];
        } catch (DecryptException|\Symfony\Component\HttpKernel\Exception\HttpException|RuntimeException) {
            $result['status'] = 'failed';
            $result['error_message'] = 'Archive export failed central size or SHA-256 verification; source archive retained.';
        } finally {
            ignore_user_abort((bool) $previousAbort);
            set_time_limit($previousLimit);
            fclose($lock);
        }

        return $result;
    }

    public function completeExport(AgentOperation $operation, array $result): void
    {
        $relay = ArchiveRelay::where('source_agent_operation_id', $operation->id)->lockForUpdate()->firstOrFail();
        if ($relay->expires_at->isPast()) {
            $result['status'] = 'failed';
            $result['error_message'] = 'Archive relay expired; source archive retained.';
        }
        if ($result['status'] === 'success') {
            abort_unless(($result['_verified_relay'] ?? null) === ['id' => $relay->id, 'size' => $relay->size_bytes, 'sha256' => $relay->sha256]
                && $relay->uploaded_bytes === $relay->size_bytes, 409, 'Archive verification is required.');
            $relay->update(['status' => 'ready']);
        }
        $redactor = new AgentOperationRedactor(['spec' => $operation->payload, 'token' => $operation->delivery_token ?? '']);
        if ($result['status'] !== 'success') {
            $relay->update(['status' => 'failed', 'error_message' => $redactor->clean($result['error_message'] ?? 'Archive export failed. The source archive has been retained.', 1000)]);
            app(RunRestore::class)->markFailed($relay->restoreRun, new RuntimeException($relay->error_message));
        }
        app(AppendRunLog::class)->handle($relay->restoreRun,
            'Archive relay export '.$result['status'].' from host '.$relay->source_docker_host_id.'; original archive retained. '.
                $redactor->clean($result['logs'] ?? ''));
        $operation->update(['status' => 'completed', 'completed_at' => now(), 'payload' => null]);
    }

    public function downloadLocal(ArchiveRelay $relay): string
    {
        $relay->refresh();
        $operation = AgentOperation::where('restore_run_id', $relay->restore_run_id)->first();
        abort_unless($relay->target_docker_host_id === DockerHost::LOCAL_ID && $relay->status === 'downloading'
            && $relay->restoreRun->status === 'running' && $operation?->status === 'running'
            && ($operation->context['local_relay_phase'] ?? null) === 'downloading', 409, 'Local relay target admission is required.');
        $spec = ['size_bytes' => (int) $relay->size_bytes, 'sha256' => $relay->sha256];

        return app(ArchiveRelayRuntime::class)->download($spec, app(ArchiveRelayStorage::class)->directory($relay->id).'/target', function (array $data) use ($relay, $spec): array {
            abort_unless(RestoreRun::whereKey($relay->restore_run_id)->where('status', 'running')->update(['last_heartbeat_at' => now()]) === 1, 409);
            AgentOperation::where('restore_run_id', $relay->restore_run_id)->where('status', 'running')->update(['last_progress_at' => now()]);
            if ($data['action'] === 'verified') {
                return ['acknowledged' => true];
            }
            $chunk = app(ArchiveRelayStorage::class)->read($relay, $data['offset']);
            $relay->update(['downloaded_bytes' => $data['offset'] + strlen($chunk)]);

            return [...$spec, 'offset' => $data['offset'], 'chunk' => base64_encode($chunk)];
        });
    }

    public function expireUnassignedTarget(ArchiveRelay $relay): bool
    {
        return DB::transaction(function () use ($relay): bool {
            DockerHost::query()->lockForUpdate()->findOrFail($relay->target_docker_host_id);
            $run = RestoreRun::query()->lockForUpdate()->findOrFail($relay->restore_run_id);
            $relay = ArchiveRelay::query()->lockForUpdate()->findOrFail($relay->id);
            $target = AgentOperation::where('restore_run_id', $run->id)->lockForUpdate()->first();
            if ($run->status !== 'queued' || $relay->expires_at->isFuture() || $relay->sourceAgentOperation->status !== 'completed'
                || ($target !== null && $target->status !== 'pending')) {
                return false;
            }
            $target?->update(['status' => 'cancelled', 'payload' => null, 'completed_at' => now()]);
            $relay->update(['status' => 'failed', 'error_message' => 'Archive relay expired before target assignment.']);

            return app(RunRestore::class)->markFailed($run, new RuntimeException('Archive relay expired before target assignment.'), RestoreRun::STATUS_QUEUED);
        }, attempts: 3);
    }

    public function coordinate(): void
    {
        foreach (ArchiveRelay::whereNull('cleaned_at')->lazyById() as $relay) {
            $source = $relay->sourceAgentOperation;
            $run = $relay->restoreRun;
            if (in_array($run->status, ['success', 'failed', 'cancelled'], true) && $source->status === 'pending') {
                DB::transaction(function () use ($source): void {
                    DockerHost::query()->lockForUpdate()->findOrFail($source->docker_host_id);
                    AgentOperation::whereKey($source->id)->where('status', 'pending')->update(['status' => 'cancelled', 'payload' => null, 'completed_at' => now()]);
                });
                $source->refresh();
            }
            if ($source->status === 'completed') {
                $this->expireUnassignedTarget($relay);
            }
            if ($run->status === 'queued' && $source->status === 'pending' && $relay->expires_at->isPast()) {
                DB::transaction(function () use ($relay, $source, $run): void {
                    DockerHost::query()->lockForUpdate()->findOrFail($source->docker_host_id);
                    $run = RestoreRun::query()->lockForUpdate()->findOrFail($run->id);
                    $relay = ArchiveRelay::query()->lockForUpdate()->findOrFail($relay->id);
                    $source = AgentOperation::query()->lockForUpdate()->findOrFail($source->id);
                    if ($source->status !== 'pending' || $run->status !== 'queued' || $relay->expires_at->isFuture()) {
                        return;
                    }
                    $source->update(['status' => 'cancelled', 'payload' => null, 'completed_at' => now()]);
                    $relay->update(['status' => 'failed', 'error_message' => 'Archive relay expired before export.']);
                    app(RunRestore::class)->markFailed($run, new RuntimeException('Archive relay expired before export.'), RestoreRun::STATUS_QUEUED);
                });
            } elseif ($source->docker_host_id === DockerHost::LOCAL_ID && in_array($source->status, ['pending', 'running'], true)) {
                ExportLocalArchiveRelay::dispatch($relay->id);
            }
            if ($relay->target_docker_host_id === DockerHost::LOCAL_ID
                && isset(AgentOperation::where('restore_run_id', $run->id)->first()?->context['local_relay_phase'])) {
                app(LocalArchiveRelayTarget::class)->recover($relay);

                continue;
            }
            if (! in_array($run->fresh()->status, ['success', 'failed', 'cancelled'], true)
                || ! in_array($source->fresh()->status, ['completed', 'cancelled'], true)) {
                continue;
            }
            $target = AgentOperation::where('restore_run_id', $run->id)->first();
            if ($target?->status === 'pending') {
                DB::transaction(function () use ($target): void {
                    DockerHost::query()->lockForUpdate()->findOrFail($target->docker_host_id);
                    AgentOperation::whereKey($target->id)->where('status', 'pending')->update(['status' => 'cancelled', 'payload' => null, 'completed_at' => now()]);
                });
                $target->refresh();
            }
            if ($target !== null && ! in_array($target->status, ['completed', 'cancelled'], true)) {
                continue;
            }
            if ($run->docker_container_id && $run->target_docker_host_id === DockerHost::LOCAL_ID) {
                if (app(ContainerIsAlive::class)->handle($run->docker_container_id) !== false) {
                    continue;
                }
                app(RemoveDockerContainer::class)->handle($run->docker_container_id);
                $run->update(['docker_container_id' => null]);
            }
            if ($run->docker_container_id || $run->stopped_container_ids) {
                continue;
            }
            $directory = app(ArchiveRelayStorage::class)->directory($relay->id);
            if (is_link($directory)) {
                continue;
            }
            $relay->update(['status' => $run->status === 'success' ? 'cleanup' : 'failed']);
            if (! is_dir($directory) || File::deleteDirectory($directory)) {
                $relay->update(['cleaned_at' => now(), 'status' => $run->status === 'success' ? 'completed' : 'failed']);
            }
        }
        $protected = AgentOperation::whereIn('status', ['pending', 'running'])->whereIn('kind', ['archive_export', 'restore'])
            ->get()->map(fn (AgentOperation $operation): ?string => $operation->payload['relay']['id'] ?? null)->filter()->all();
        $root = rtrim(config('volumevault.archive_relay.directory'), '/');
        if (is_link($root)) {
            return;
        }
        foreach (glob($root.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $id = basename($directory);
            if (! Str::isUuid($id) || is_link($directory) || in_array($id, $protected, true)
                || filemtime($directory) > time() - max(60, (int) config('volumevault.archive_relay.ttl_seconds'))
                || ArchiveRelay::whereKey($id)->exists()) {
                continue;
            }
            File::deleteDirectory($directory);
        }
    }
}
