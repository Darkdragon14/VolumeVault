<?php

namespace App\Services\BackupDestinations;

use App\Actions\Docker\CleanupDestinationOperationHelper;
use App\Models\BackupDestination;

class ExecuteDestinationOperation
{
    public function handle(array $spec, ?string $operationId = null): array
    {
        $started = microtime(true);
        $docker = $spec['destination']['provider'] === BackupDestination::PROVIDER_DOCKER_VOLUME;
        $storage = app(DestinationStorage::class);
        if ($docker && $operationId === null) {
            throw new \RuntimeException('A durable operation identity is required for Docker destination helpers.');
        }
        try {
            $destination = new BackupDestination([...$spec['destination'], 'docker_host_id' => 1]);
            if ($docker) {
                $storage->useOperationHelper(CleanupDestinationOperationHelper::name($operationId));
            }
            if ($spec['action'] === 'host_key') {
                $data = $storage->probeHostKey($spec['destination']['host'], $spec['destination']['port']);
            } elseif ($spec['action'] === 'metadata') {
                if ($destination->provider === BackupDestination::PROVIDER_DROPBOX) {
                    if (! str_starts_with((string) ($spec['archive']['key'] ?? ''), 'id:')) {
                        throw new \RuntimeException('Dropbox upload did not capture a stable file ID.');
                    }
                    $object = $storage->findBackupObjectByKey($destination, $spec['archive']['key']);
                    if ($object === null || ($object['key'] ?? null) !== $spec['archive']['key'] || ! isset($object['size'])) {
                        throw new \RuntimeException('Dropbox archive metadata could not be detected by stable file ID.');
                    }
                    $data = ['backup_key' => $spec['archive']['key'], 'backup_size_bytes' => (int) $object['size']];
                } else {
                    $object = $storage->findBackupObjectByFilename($destination, $spec['archive']['filename']);
                    if ($object === null) {
                        throw new \RuntimeException('Backup archive metadata could not be detected.');
                    }
                    $data = ['backup_key' => (string) ($object['key'] ?? $object['display_name'] ?? $spec['archive']['filename']),
                        'backup_size_bytes' => array_key_exists('size', $object) ? (int) $object['size'] : null];
                }
            } elseif ($spec['action'] === 'test') {
                $storage->testReadOnly($destination);
                $data = ['ok' => true];
            } elseif ($spec['action'] === 'list' && isset($spec['selected_backup'])) {
                $selected = $spec['selected_backup'];
                $object = $storage->findBackupObjectByKey($destination, $selected['key']);
                if ($object !== null && ($object['key'] ?? null) !== $selected['key']) {
                    throw new \RuntimeException('Unexpected historical archive identity.');
                }
                $data = ['objects' => $object === null ? [] : [[
                    'key' => $selected['key'], 'display_name' => $object['display_name'] ?? $selected['display_name'],
                    'size' => $object['size'] ?? $selected['size'] ?? 0,
                    'last_modified' => $object['last_modified'] ?? $selected['last_modified'],
                ]], 'next_cursor' => null];
            } else {
                $data = $spec['action'] === 'list'
                    ? $storage->listBackupObjectsPage($destination, $spec['cursor'] ?? null, $spec['limit'])
                    : $storage->freshStorageUsage($destination);
            }
            $result = $this->result('success', $data, $started);
            app(DestinationOperations::class)->validateResult($spec['action'], $result, $spec['limit']);

        } catch (\Throwable) {
            $result = $this->result('failed', null, $started);
        } finally {
            if ($docker) {
                $storage->useOperationHelper(null);
            }
        }
        $result['cleanup_complete'] = ! $docker || app(CleanupDestinationOperationHelper::class)->handle($operationId);

        return $result;
    }

    public function recover(array $spec, string $operationId): array
    {
        $result = $this->result('failed', null, microtime(true));
        $result['cleanup_complete'] = $spec['destination']['provider'] !== BackupDestination::PROVIDER_DOCKER_VOLUME
            || app(CleanupDestinationOperationHelper::class)->handle($operationId);

        return $result;
    }

    private function result(string $status, ?array $data, float $started): array
    {
        return ['status' => $status, 'data' => $data, 'logs' => '',
            'error_message' => $status === 'failed' ? 'Destination operation failed. Check credentials, reachability and host policy.' : null,
            'cleanup_complete' => true, 'finished_at' => now()->toIso8601String(), 'duration_seconds' => (int) (microtime(true) - $started)];
    }
}
