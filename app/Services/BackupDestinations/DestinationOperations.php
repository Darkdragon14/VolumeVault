<?php

namespace App\Services\BackupDestinations;

use App\Actions\Restore\ResolveRestoreDestination;
use App\Jobs\RunDestinationOperation;
use App\Models\AgentOperation;
use App\Models\BackupDestination;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Services\Agents\AgentExecution;
use App\Services\Agents\AgentOperationRedactor;
use App\Services\Agents\AgentOperationSpecification;
use App\Services\Agents\DispatchAgentOperation;
use App\Services\Agents\HostWorkAdmission;
use App\Services\Docker\LocalDockerExecution;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DestinationOperations
{
    public function hostOptions(): array
    {
        return DockerHost::orderBy('name')->get()->map(fn (DockerHost $host): array => [
            ...app(AgentExecution::class)->summary($host),
            'supports_destination_operations' => $host->isLocal() || app(AgentExecution::class)->supportsHost($host, 'destination-v1'),
            'supports_sftp_host_key' => $host->isLocal() || (app(AgentExecution::class)->supportsHost($host, 'destination-v1') && app(AgentExecution::class)->supportsHost($host, 'sftp-host-key-v1')),
            'supports_host_bound_destinations' => app(AgentExecution::class)->supportsHost($host, 'destination-v1'),
        ])->all();
    }

    public function hostId(BackupDestination $destination, ?int $selected = null): int
    {
        $hostId = $selected ?? ($destination->isHostBound() ? (int) $destination->docker_host_id : DockerHost::LOCAL_ID);
        if ($destination->isHostBound() && $hostId !== (int) $destination->docker_host_id) {
            throw ValidationException::withMessages(['docker_host_id' => 'This destination can only be accessed by its owning host.']);
        }
        if ($hostId !== DockerHost::LOCAL_ID) {
            app(AgentExecution::class)->validateHost($hostId, 'destination-v1');
        } elseif ($destination->isHostBound()) {
            LocalDockerExecution::validate();
        }

        return $hostId;
    }

    public function create(BackupDestination $destination, string $action, ?int $hostId = null, ?string $cursor = null, int $limit = 1000, ?int $backupRunId = null): AgentOperation
    {
        return DB::transaction(function () use ($destination, $action, $hostId, $cursor, $limit, $backupRunId): AgentOperation {
            $destination = BackupDestination::query()->lockForUpdate()->findOrFail($destination->id);
            $hostId = $this->hostId($destination, $hostId);
            if ($hostId !== DockerHost::LOCAL_ID || $destination->isHostBound()) {
                DockerHost::query()->whereKey($hostId)->lockForUpdate()->firstOrFail();
                app(HostWorkAdmission::class)->assertAccepting($hostId);
            }
            $providerCursor = null;
            if ($cursor !== null) {
                try {
                    $decoded = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
                    if ($action !== 'list' || $decoded['destination_id'] !== $destination->id || $decoded['host_id'] !== $hostId
                        || $decoded['locator'] !== $destination->locatorFingerprint() || $decoded['limit'] !== $limit || $decoded['expires'] < time()) {
                        throw new \RuntimeException;
                    }
                    $providerCursor = $decoded['cursor'];
                } catch (\Throwable) {
                    throw ValidationException::withMessages(['cursor' => 'The listing cursor is invalid or expired.']);
                }
            }
            $id = (string) Str::uuid();
            $spec = ['version' => 1, 'destination' => app(DispatchAgentOperation::class)->destination($destination), 'action' => $action, 'cursor' => $providerCursor, 'limit' => $limit];
            if ($backupRunId !== null) {
                $run = BackupRun::query()->lockForUpdate()->find($backupRunId);
                if ($action !== 'list' || $cursor !== null || ! $run?->job) {
                    throw ValidationException::withMessages(['backup_run_id' => 'Select an available historical backup for an exact archive listing.']);
                }
                $resolved = app(ResolveRestoreDestination::class)->handle($run->job, $run->id);
                if ($resolved->id !== $destination->id || ListBackupObjects::isRunUnverifiable($destination, $run)) {
                    throw ValidationException::withMessages(['backup_run_id' => 'The selected run does not identify a verifiable archive on this destination.']);
                }
                $spec['selected_backup'] = ['key' => $run->backup_key, 'display_name' => $run->backup_filename ?: $run->backup_key,
                    'size' => $run->backup_size_bytes, 'last_modified' => $run->finished_at?->toIso8601String()];
            }
            try {
                app(AgentOperationSpecification::class)->validate(['id' => $id, 'token' => str_repeat('0', 64), 'kind' => 'destination', 'spec' => $spec]);
            } catch (\RuntimeException) {
                throw ValidationException::withMessages(['destination' => 'The destination configuration cannot be used for this operation.']);
            }
            $operation = AgentOperation::create([
                'id' => $id, 'docker_host_id' => $hostId, 'kind' => 'destination', 'status' => 'pending',
                'backup_destination_id' => $destination->id, 'destination_action' => $action,
                'locator_fingerprint' => $destination->locatorFingerprint(), 'payload' => $spec,
                'context' => ['destination_name' => $destination->name, 'provider' => $destination->provider, 'limit' => $limit, 'backup_run_id' => $backupRunId,
                    'storage_measurement_fingerprint' => $destination->storageMeasurementFingerprint()],
            ]);
            if ($hostId === DockerHost::LOCAL_ID) {
                RunDestinationOperation::dispatch($id)->afterCommit();
            }

            return $operation;
        });
    }

    public function createHostKey(string $host, int $port, int $hostId): AgentOperation
    {
        Validator::make(['host' => $host], ['host' => ['required', new SftpEndpointHost]])->validate();
        return DB::transaction(function () use ($host, $port, $hostId): AgentOperation {
            app(AgentExecution::class)->validateHost($hostId, 'sftp-host-key-v1');
            app(AgentExecution::class)->validateHost($hostId, 'destination-v1');
            DockerHost::query()->whereKey($hostId)->lockForUpdate()->firstOrFail();
            app(HostWorkAdmission::class)->assertAccepting($hostId);
            $id = (string) Str::uuid();
            $spec = ['version' => 1, 'action' => 'host_key', 'limit' => 1, 'destination' => ['provider' => 'ssh', 'host' => $host, 'port' => $port]];
            app(AgentOperationSpecification::class)->validate(['id' => $id, 'token' => str_repeat('0', 64), 'kind' => 'destination', 'spec' => $spec]);

            return AgentOperation::create([
                'id' => $id, 'docker_host_id' => $hostId, 'kind' => 'destination', 'status' => 'pending',
                'destination_action' => 'host_key', 'payload' => $spec,
                'context' => ['destination_name' => $host, 'host' => $host, 'port' => $port, 'provider' => 'ssh', 'limit' => 1],
            ]);
        });
    }

    public function complete(AgentOperation $operation, array $result): void
    {
        $this->validateResult($operation->destination_action, $result, $operation->payload['limit'] ?? 1000);
        if (isset($operation->payload['selected_backup']) && $result['status'] === 'success') {
            abort_if(count($result['data']['objects']) > 1 || $result['data']['next_cursor'] !== null
                || collect($result['data']['objects'])->contains(fn (array $object): bool => $object['key'] !== $operation->payload['selected_backup']['key']), 422, 'Unexpected historical archive identity.');
        }
        $redactor = new AgentOperationRedactor(['spec' => $operation->payload, 'token' => $operation->delivery_token ?? '']);
        $result['logs'] = $redactor->clean($result['logs'] ?? '');
        $result['error_message'] = $result['status'] === 'success' ? null : $redactor->clean($result['error_message'] ?? 'Destination operation failed.');
        $operation->forceFill(['status' => 'completed', 'completed_at' => now(), 'result' => $result, 'payload' => null])->save();
    }

    public function validateResult(string $action, array $result, int $limit): void
    {
        abort_if(strlen(json_encode($result, JSON_THROW_ON_ERROR)) > 2 * 1024 * 1024, 422, 'Destination result exceeds the size limit.');
        $rules = [
            'status' => ['required', 'in:success,failed'], 'cleanup_complete' => ['required', 'accepted', 'boolean:strict'],
            'logs' => ['nullable', 'string', 'max:262144'], 'error_message' => ['nullable', 'string', 'max:1000'],
            'finished_at' => ['required', 'string', 'max:64', 'date'], 'duration_seconds' => ['required', 'integer:strict', 'min:0'],
            'data' => ['present', 'nullable', 'array'],
        ];
        $dataRules = match ($action) {
            'metadata' => ['data' => ['required', 'array:backup_key,backup_size_bytes'], 'data.backup_key' => ['required', 'string', 'max:4096'], 'data.backup_size_bytes' => ['present', 'nullable', 'integer:strict', 'min:0']],
            'host_key' => ['data' => ['required', 'array:key,fingerprint'], 'data.key' => ['required', 'string', 'max:4096'], 'data.fingerprint' => ['required', 'string', 'max:128']],
            'test' => ['data' => ['required', 'array:ok'], 'data.ok' => ['required', 'boolean:strict', 'accepted']],
            'stats' => ['data' => ['required', 'array:used_bytes,object_count'], 'data.used_bytes' => ['required', 'integer:strict', 'min:0'], 'data.object_count' => ['required', 'integer:strict', 'min:0']],
            'list' => [
                'data' => ['required', 'array:objects,next_cursor'], 'data.objects' => ['present', 'array', 'list', 'max:'.$limit],
                'data.objects.*' => ['array:key,display_name,size,last_modified'],
                'data.objects.*.key' => ['required', 'string', 'max:4096', 'distinct:strict'],
                'data.objects.*.display_name' => ['required', 'string', 'max:4096'],
                'data.objects.*.size' => ['required', 'integer:strict', 'min:0'],
                'data.objects.*.last_modified' => ['present', 'nullable', 'string', 'max:64', 'date'],
                'data.next_cursor' => ['present', 'nullable', 'string', 'max:16384'],
            ],
        };
        abort_if(array_diff(array_keys($result), array_keys($rules)) !== [], 422, 'Unexpected destination result fields.');
        abort_if(($result['status'] ?? null) === 'failed' && ($result['data'] ?? null) !== null, 422, 'Failed operations cannot report data.');
        Validator::make($result, ($result['status'] ?? null) === 'success' ? [...$rules, ...$dataRules] : $rules)->validate();
    }

    public function safe(AgentOperation $operation): array
    {
        $result = $operation->result;
        if (isset($result['data']['next_cursor'])) {
            $result['data']['next_cursor'] = Crypt::encryptString(json_encode([
                'destination_id' => $operation->backup_destination_id, 'host_id' => $operation->docker_host_id,
                'locator' => $operation->locator_fingerprint, 'cursor' => $result['data']['next_cursor'],
                'expires' => $operation->completed_at->copy()->addHour()->timestamp, 'limit' => $operation->context['limit'],
            ], JSON_THROW_ON_ERROR));
        }

        return [
            'id' => $operation->id, 'destination_id' => $operation->backup_destination_id,
            'endpoint' => $operation->destination_action === 'host_key' ? ['host' => $operation->context['host'], 'port' => $operation->context['port']] : null,
            'docker_host_id' => $operation->docker_host_id, 'action' => $operation->destination_action,
            'backup_run_id' => $operation->context['backup_run_id'] ?? null,
            'status' => $operation->status, 'destination_name' => $operation->context['destination_name'],
            'fresh_until' => $operation->completed_at ? ($operation->claimed_at ?? $operation->created_at)->copy()->addMinutes(30) : null,
            'locator_current' => BackupDestination::find($operation->backup_destination_id)?->locatorFingerprint() === $operation->locator_fingerprint,
            'created_at' => $operation->created_at, 'completed_at' => $operation->completed_at, 'result' => $result,
        ];
    }

    public function usage(BackupDestination $destination): array
    {
        $hostId = $this->hostId($destination, $destination->storageMeasurementHostId());
        $query = AgentOperation::where('backup_destination_id', $destination->id)->where('docker_host_id', $hostId)
            ->where('locator_fingerprint', $destination->locatorFingerprint())->where('destination_action', 'stats');
        $current = fn (AgentOperation $operation): bool => ($operation->context['storage_measurement_fingerprint'] ?? null) === $destination->storageMeasurementFingerprint();
        $latest = (clone $query)->where('status', 'completed')->latest('completed_at')->cursor()->first($current);
        if ($latest && ($latest->claimed_at ?? $latest->created_at)->gt(now()->subMinutes(30)) && $latest->result['status'] === 'success') {
            return $latest->result['data'];
        }
        if (! (clone $query)->whereIn('status', ['pending', 'running'])->cursor()->contains($current)) {
            $this->create($destination, 'stats', $hostId);
        }
        throw new \RuntimeException('Destination storage measurement is pending or stale.');
    }

    public function verifies(BackupDestination $destination, int $hostId, string $id, string $key): bool
    {
        $operation = AgentOperation::whereKey($id)->where('backup_destination_id', $destination->id)
            ->where('docker_host_id', $hostId)->where('locator_fingerprint', $destination->locatorFingerprint())
            ->where('destination_action', 'list')->where('status', 'completed')
            ->where(fn ($query) => $query->where('claimed_at', '>', now()->subMinutes(30))->orWhere(fn ($query) => $query->whereNull('claimed_at')->where('created_at', '>', now()->subMinutes(30))))->first();

        return $operation && ($operation->result['status'] ?? null) === 'success'
            && collect($operation->result['data']['objects'])->contains(fn (array $object): bool => $object['key'] === $key);
    }
}
