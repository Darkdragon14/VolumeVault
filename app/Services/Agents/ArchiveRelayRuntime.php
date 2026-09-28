<?php

namespace App\Services\Agents;

use App\Actions\Docker\CleanupDestinationOperationHelper;
use App\Models\BackupDestination;
use App\Services\BackupDestinations\DestinationStorage;
use RuntimeException;

class ArchiveRelayRuntime
{
    public function export(array $operation, string $directory, bool $initialize, callable $upload): ?array
    {
        $spec = $operation['spec'];
        $id = $operation['id'];
        $docker = $spec['destination']['provider'] === 'docker_volume';
        $archive = $directory.'/export.tar.gz';
        $manifest = $directory.'/export.json';
        $storage = app(DestinationStorage::class);
        $max = min($spec['relay']['max_bytes'], (int) config('volumevault.archive_relay.max_bytes'));
        app(ArchiveRelayStorage::class)->secureDirectory($directory);
        if (is_link($archive) || is_link($manifest)) {
            throw new RuntimeException('Unsafe relay storage.');
        }
        if ($initialize && ! is_file($manifest)) {
            try {
                if ($max <= 0 || disk_free_space($directory) < 2 * $max) {
                    throw new RuntimeException('Insufficient relay disk space.');
                }
                app(AgentOperationSpecification::class)->validateLocalPolicy($operation);
                $storage->useOperationHelper($docker ? CleanupDestinationOperationHelper::name($id) : null);
                $storage->useOperationLimit($max);
                $destination = new BackupDestination([...$spec['destination'], 'docker_host_id' => 1]);
                if ($docker) {
                    app(\App\Actions\Docker\ExportRelayArchive::class)->handle($destination, $spec['relay']['key'], $archive, $id, $max);
                } else {
                    $storage->download($destination, $spec['relay']['key'], $archive);
                }
                clearstatcache(true, $archive);
                $size = filesize($archive);
                if ($size <= 0 || $size > $max || ! chmod($archive, 0600)) {
                    throw new RuntimeException('Archive exceeds the relay size limit.');
                }
                $stream = fopen($archive, 'rb');
                try {
                    if (! fsync($stream)) {
                        throw new RuntimeException('Unable to persist export.');
                    }
                } finally {
                    fclose($stream);
                }
                $this->writeManifest($manifest, ['size_bytes' => $size, 'sha256' => hash_file('sha256', $archive)]);
            } catch (\Throwable $exception) {
                $redactor = new AgentOperationRedactor([...$operation, 'token' => $operation['token'] ?? '']);
                $this->writeManifest($manifest, ['failed' => true, 'error_message' => $redactor->clean($exception->getMessage(), 1000)]);
            } finally {
                $storage->useOperationHelper(null);
                $storage->useOperationLimit(null);
            }
        }
        if ($docker && ! app(CleanupDestinationOperationHelper::class)->handle($id)) {
            return null;
        }
        $metadata = is_file($manifest) ? json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR) : ['failed' => true];
        if (isset($metadata['failed']) || ! is_file($archive)) {
            return $this->result(false, $metadata['error_message'] ?? null);
        }
        if (filesize($archive) !== $metadata['size_bytes'] || ! hash_equals($metadata['sha256'], hash_file('sha256', $archive))) {
            return $this->result(false);
        }
        $stream = fopen($archive, 'rb');
        try {
            $offset = $metadata['uploaded_bytes'] ?? 0;
            if (! is_int($offset) || $offset < 0 || $offset > $metadata['size_bytes']
                || ($offset !== $metadata['size_bytes'] && $offset % ArchiveRelayStorage::CHUNK_BYTES !== 0)
                || fseek($stream, $offset) !== 0) {
                throw new RuntimeException('Invalid durable upload offset.');
            }
            while ($offset < $metadata['size_bytes']) {
                $chunk = fread($stream, ArchiveRelayStorage::CHUNK_BYTES);
                $response = $upload(['action' => 'upload', 'offset' => $offset, 'chunk' => base64_encode($chunk),
                    'size_bytes' => $metadata['size_bytes'], 'sha256' => $metadata['sha256']]);
                $next = $response['offset'] ?? -1;
                if (! is_int($next) || $next < $offset + strlen($chunk) || $next > $metadata['size_bytes']
                    || ($next !== $metadata['size_bytes'] && $next % ArchiveRelayStorage::CHUNK_BYTES !== 0)) {
                    throw new RuntimeException('Archive upload was not acknowledged.');
                }
                $metadata['uploaded_bytes'] = $offset = $next;
                $this->writeManifest($manifest, $metadata);
                if (fseek($stream, $offset) !== 0) {
                    throw new RuntimeException('Unable to resume archive upload.');
                }
            }
        } finally {
            fclose($stream);
        }

        return $this->result(true);
    }

    public function download(array $relay, string $directory, callable $download): string
    {
        app(ArchiveRelayStorage::class)->secureDirectory($directory);
        $path = $directory.'/relay.tar.gz';
        if (is_link($path) || $relay['size_bytes'] > (int) config('volumevault.archive_relay.max_bytes')) {
            throw new RuntimeException('Invalid relay download.');
        }
        $mask = umask(0077);
        $stream = fopen($path, 'c+b');
        umask($mask);
        chmod($path, 0600);
        try {
            $size = fstat($stream)['size'];
            if ($size > $relay['size_bytes']) {
                throw new RuntimeException('Relay download size changed.');
            }
            $offset = $size === $relay['size_bytes'] ? $size : intdiv($size, ArchiveRelayStorage::CHUNK_BYTES) * ArchiveRelayStorage::CHUNK_BYTES;
            if (disk_free_space($directory) < 2 * ($relay['size_bytes'] - $offset)) {
                throw new RuntimeException('Insufficient relay download disk space.');
            }
            if (! ftruncate($stream, $offset) || fseek($stream, $offset) !== 0) {
                throw new RuntimeException('Unable to resume relay download.');
            }
            while ($offset < $relay['size_bytes']) {
                $response = $download(['action' => 'download', 'offset' => $offset]);
                $chunk = base64_decode($response['chunk'] ?? '', true);
                if (($response['offset'] ?? null) !== $offset || ($response['size_bytes'] ?? null) !== $relay['size_bytes']
                    || ($response['sha256'] ?? null) !== $relay['sha256'] || ! is_string($chunk)
                    || strlen($chunk) !== min(ArchiveRelayStorage::CHUNK_BYTES, $relay['size_bytes'] - $offset)) {
                    throw new RuntimeException('Invalid relay download chunk.');
                }
                if (fwrite($stream, $chunk) !== strlen($chunk) || ! fflush($stream) || ! fsync($stream)) {
                    throw new RuntimeException('Unable to persist relay download.');
                }
                $offset += strlen($chunk);
            }
        } finally {
            fclose($stream);
        }
        if (! hash_equals($relay['sha256'], hash_file('sha256', $path))) {
            throw new RuntimeException('Relay archive integrity verification failed.');
        }
        $response = $download(['action' => 'verified', 'offset' => $relay['size_bytes']]);
        if (($response['acknowledged'] ?? false) !== true) {
            throw new RuntimeException('Verified download was not acknowledged.');
        }

        return $path;
    }

    private function writeManifest(string $path, array $data): void
    {
        if (is_link($path.'.tmp')) {
            throw new RuntimeException('Unsafe relay manifest.');
        }
        $stream = fopen($path.'.tmp', 'wb');
        chmod($path.'.tmp', 0600);
        try {
            $contents = json_encode($data, JSON_THROW_ON_ERROR);
            if (fwrite($stream, $contents) !== strlen($contents) || ! fflush($stream) || ! fsync($stream) || ! rename($path.'.tmp', $path)) {
                throw new RuntimeException('Unable to persist export manifest.');
            }
            $directory = fopen(dirname($path), 'r');
            try {
                if (! fsync($directory)) {
                    throw new RuntimeException('Unable to persist export directory.');
                }
            } finally {
                fclose($directory);
            }
        } finally {
            fclose($stream);
        }
    }

    private function result(bool $success, ?string $error = null): array
    {
        return ['status' => $success ? 'success' : 'failed', 'cleanup_complete' => true,
            'logs' => $success ? 'Archive export completed; source archive retained.' : 'Archive export failed; source archive retained. '.($error ?? ''),
            'error_message' => $success ? null : ($error ?: 'Archive export failed.'), 'duration_seconds' => 0, 'finished_at' => now()->toIso8601String()];
    }
}
