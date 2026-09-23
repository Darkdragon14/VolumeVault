<?php

namespace App\Services\BackupDestinations;

use App\Models\AgentOperation;
use App\Models\DockerHost;
use App\Models\RunFinalization;
use App\Services\Agents\AgentExecution;
use App\Services\Agents\HostWorkAdmission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class RemoteArchiveMetadata
{
    /** @return array{backup_key: string, backup_size_bytes: ?int}|null */
    public function detect(RunFinalization $finalization): ?array
    {
        $operation = DB::transaction(function () use ($finalization): AgentOperation {
            $locked = RunFinalization::query()->lockForUpdate()->findOrFail($finalization->id);
            $spec = $locked->remote_metadata_payload;
            if ($spec['destination']['provider'] === 'dropbox' && ! str_starts_with((string) ($spec['archive']['key'] ?? ''), 'id:')) {
                throw new RuntimeException('Dropbox archive identity cannot be verified because the upload did not capture a stable file ID.');
            }
            $context = $locked->context ?? [];
            if (isset($context['metadata_operation_id'])) {
                return AgentOperation::findOrFail($context['metadata_operation_id']);
            }
            $hostId = (int) $context['docker_host_id'];
            $host = DockerHost::query()->lockForUpdate()->findOrFail($hostId);
            if (! app(AgentExecution::class)->supportsHost($host, 'archive-metadata-v1') || ! app(AgentExecution::class)->supportsHost($host, 'destination-v1')) {
                throw new RuntimeException('Archive metadata retry requires agent capability archive-metadata-v1.');
            }
            app(HostWorkAdmission::class)->assertAccepting($hostId);
            $operation = AgentOperation::create([
                'id' => (string) Str::uuid(), 'docker_host_id' => $hostId, 'kind' => 'destination', 'status' => 'pending',
                'destination_action' => 'metadata', 'payload' => $locked->remote_metadata_payload,
                'context' => ['destination_name' => $locked->remote_metadata_payload['destination']['name'], 'limit' => 1],
            ]);
            $locked->update(['context' => [...$context, 'metadata_operation_id' => $operation->id]]);

            return $operation;
        });

        if (in_array($operation->status, ['pending', 'running'], true)) {
            if ($operation->created_at->lte(now()->subMinutes(30))) {
                $cancelled = AgentOperation::whereKey($operation->id)->where('status', 'pending')->update(['status' => 'cancelled', 'payload' => null, 'completed_at' => now()]);
                if ($cancelled === 1) {
                    $context = $finalization->fresh()->context;
                    unset($context['metadata_operation_id']);
                    $finalization->update(['context' => $context]);
                }
                throw new RuntimeException('Archive metadata operation exceeded its 30 minute deadline.');
            }

            return null;
        }
        if (($operation->result['status'] ?? null) !== 'success') {
            $context = $finalization->fresh()->context;
            unset($context['metadata_operation_id']);
            $finalization->update(['context' => $context]);
            throw new RuntimeException('Backup archive metadata could not be detected on its assigned agent.');
        }

        return $operation->result['data'];
    }
}
