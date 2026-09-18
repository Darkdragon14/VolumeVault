<?php

namespace App\Services\Agents;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class AgentOperationStore
{
    private bool $storeLocked = false;

    public function root(): string
    {
        $root = (string) config('volumevault.agents.client.state_directory', storage_path('app/agent'));

        return str_starts_with($root, '/') ? rtrim($root, '/') : base_path($root);
    }

    public function directory(string $id): string
    {
        $this->assertId($id);

        return $this->root().'/operations/'.$id;
    }

    public function assertId(string $id): void
    {
        if (! Str::isUuid($id) || strtolower($id) !== $id) {
            throw new RuntimeException('Invalid operation identity.');
        }
    }

    /** @param array<string, mixed> $operation */
    public function accept(array $operation): void
    {
        app(AgentOperationSpecification::class)->validate($operation);
        $this->locked(function () use ($operation): void {
            $id = $operation['id'];
            $fingerprint = hash_hmac('sha256', json_encode($this->canonical([$operation['kind'], $operation['spec']]), JSON_THROW_ON_ERROR), $this->key());
            $existing = $this->read($id);
            if ($existing !== null) {
                if (! hash_equals($existing['token'], $operation['token']) || ! hash_equals($existing['fingerprint'], $fingerprint)) {
                    throw new RuntimeException('Conflicting operation delivery.');
                }

                return;
            }
            if ($this->active() !== []) {
                throw new RuntimeException('An operation is already outstanding on this host.');
            }
            if (file_exists($this->directory($id)) || file_exists($this->directory($id).'.worker.lock') || file_exists($this->receiptPath($id).'.worker.lock') || is_link($this->receiptPath($id).'.worker.lock')) {
                throw new RuntimeException('Operation receipt is missing; refusing to recreate it.');
            }
            $this->write($id, [...$operation, 'fingerprint' => $fingerprint, 'phase' => 'accepted', 'accepted_at' => gmdate(DATE_ATOM)]);
        });
    }

    /** @return array<string, mixed>|null */
    public function read(string $id): ?array
    {
        $this->safeFile($this->root());
        $this->safeFile($this->root().'/operations');
        $path = $this->directory($id).'.json';
        $this->safeFile($path);
        $archived = $this->receiptPath($id).'.json';
        $this->safeFile($archived);
        if (is_file($archived)) {
            $path = $archived;
        }
        if (! is_file($path)) {
            return null;
        }

        try {
            return json_decode($this->cipher()->decryptString(file_get_contents($path)), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new RuntimeException('Operation journal is unreadable; recovery required.');
        }
    }

    /** @return list<array<string, mixed>> */
    public function active(): array
    {
        return $this->locked(function (): array {
            $ids = [];
            foreach (glob($this->root().'/operations/*') ?: [] as $path) {
                $this->safeFile($path);
                if (basename($path) === 'receipts') {
                    continue;
                }
                $id = match (true) {
                    is_dir($path) => basename($path),
                    str_ends_with($path, '.worker.lock') => basename($path, '.worker.lock'),
                    str_ends_with($path, '.json') => basename($path, '.json'),
                    default => null,
                };
                if ($id !== null) {
                    $this->assertId($id);
                    $ids[$id] = true;
                }
            }
            $entries = [];
            foreach (array_keys($ids) as $id) {
                $entry = $this->read($id) ?? throw new RuntimeException('Operation receipt is missing; host requires recovery.');
                if ($entry['phase'] === 'acknowledged') {
                    // Also finish interrupted archival and migrate legacy tombstones.
                    $this->acknowledge($id);
                } else {
                    $entries[] = $entry;
                }
            }

            return $entries;
        });
    }

    public function markExecuting(string $id): void
    {
        $this->locked(function () use ($id): void {
            $entry = $this->read($id) ?? throw new RuntimeException('Unknown operation.');
            if ($entry['phase'] === 'accepted') {
                $entry['phase'] = 'executing';
                $this->write($id, $entry);
            }
        });
    }

    /** @param array<string, mixed> $result */
    public function finish(string $id, array $result): void
    {
        if (($result['cleanup_complete'] ?? false) !== true) {
            throw new RuntimeException('Operation cleanup is incomplete.');
        }
        $this->locked(function () use ($id, $result): void {
            $entry = $this->read($id) ?? throw new RuntimeException('Unknown operation.');
            if (in_array($entry['phase'], ['finished', 'acknowledged'], true)) {
                return;
            }
            $this->write($id, [...$entry, 'phase' => 'finished', 'result' => $result]);
        });
    }

    public function acknowledge(string $id): void
    {
        $worker = $this->workerLock($id);
        if ($worker === null) {
            throw new RuntimeException('Operation worker is still finishing; retry acknowledgement.');
        }
        try {
            $this->locked(function () use ($id, $worker): void {
                $entry = $this->read($id) ?? throw new RuntimeException('Unknown operation.');
                if (! in_array($entry['phase'], ['finished', 'acknowledged'], true)) {
                    throw new RuntimeException('Operation has no completed result.');
                }
                $this->secureDirectory($this->root().'/operations/receipts');
                $receipt = $this->receiptPath($id);
                $tombstone = array_intersect_key([...$entry, 'phase' => 'acknowledged'], array_flip(['id', 'token', 'fingerprint', 'phase']));
                // Publish and fsync the tombstone before removing any recovery data.
                $this->atomicWrite($receipt.'.json', $this->cipher()->encryptString(json_encode($tombstone, JSON_THROW_ON_ERROR)));
                $marker = $this->directory($id).'.worker.lock';
                $this->safeFile($marker);
                $this->safeFile($receipt.'.worker.lock');
                if (! fsync($worker)) {
                    throw new RuntimeException('Unable to persist operation worker marker.');
                }
                if (is_file($marker)) {
                    if (file_exists($receipt.'.worker.lock') || ! rename($marker, $receipt.'.worker.lock')) {
                        throw new RuntimeException('Unable to archive operation worker marker.');
                    }
                    $this->syncDirectory(dirname($receipt));
                    $this->syncDirectory(dirname($marker));
                }
                $directory = $this->directory($id);
                $this->safeFile($directory);
                if (is_dir($directory)) {
                    if (! File::deleteDirectory($directory)) {
                        throw new RuntimeException('Unable to remove acknowledged operation data.');
                    }
                }
                $journal = $directory.'.json';
                $this->safeFile($journal);
                if (is_file($journal) && ! unlink($journal)) {
                    throw new RuntimeException('Unable to remove acknowledged operation journal.');
                }
                $this->syncDirectory(dirname($directory));
            });
        } finally {
            fclose($worker);
        }
    }

    /** The caller must hold the returned resource until all runtime work ends. */
    public function workerLock(string $id): mixed
    {
        return $this->locked(function () use ($id): mixed {
            $entry = $this->read($id) ?? throw new RuntimeException('Unknown operation.');
            $path = $this->directory($id).'.worker.lock';
            $this->safeFile($path);
            if ($entry['phase'] === 'acknowledged' && ! is_file($path)) {
                $this->secureDirectory($this->root().'/operations/receipts');
                $path = $this->receiptPath($id).'.worker.lock';
                $this->safeFile($path);
            }
            $handle = fopen($path, 'c');
            chmod($path, 0600);
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                fclose($handle);

                return null;
            }

            return $handle;
        });
    }

    public function localKey(): string
    {
        return $this->locked(fn (): string => 'base64:'.base64_encode($this->key()));
    }

    public function secureDirectory(string $path): void
    {
        if (is_link($path) || (! is_dir($path) && ! @mkdir($path, 0700, true)) || ! chmod($path, 0700)) {
            throw new RuntimeException('Unable to secure operation storage.');
        }
    }

    private function locked(callable $callback): mixed
    {
        if ($this->storeLocked) {
            return $callback();
        }
        $this->secureDirectory($this->root());
        $this->secureDirectory($this->root().'/operations');
        $path = $this->root().'/operations/store.lock';
        $this->safeFile($path);
        $handle = fopen($path, 'c');
        chmod($path, 0600);
        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock operation storage.');
            }

            $this->storeLocked = true;

            return $callback();
        } finally {
            $this->storeLocked = false;
            fclose($handle);
        }
    }

    private function key(): string
    {
        $path = $this->root().'/operations.key';
        $this->safeFile($path);
        if (! is_file($path)) {
            $this->safeFile($this->root().'/operations/receipts');
            if ((glob($this->root().'/operations/*.json') ?: []) !== [] || (glob($this->root().'/operations/receipts/*') ?: []) !== [] || (glob($this->root().'/operations/*.worker.lock') ?: []) !== []) {
                throw new RuntimeException('Operation key is missing; recovery required.');
            }
            $this->atomicWrite($path, random_bytes(32));
        }
        $key = file_get_contents($path);
        if (strlen($key) !== 32 || ! chmod($path, 0600)) {
            throw new RuntimeException('Invalid operation key.');
        }

        return $key;
    }

    private function cipher(): Encrypter
    {
        return new Encrypter($this->key(), 'AES-256-CBC');
    }

    /** @param array<string, mixed> $entry */
    private function write(string $id, array $entry): void
    {
        $this->atomicWrite($this->directory($id).'.json', $this->cipher()->encryptString(json_encode($entry, JSON_THROW_ON_ERROR)));
    }

    private function safeFile(string $path): void
    {
        if (is_link($path)) {
            throw new RuntimeException('Unsafe operation storage.');
        }
    }

    private function receiptPath(string $id): string
    {
        $this->assertId($id);
        $this->safeFile($this->root().'/operations/receipts');

        return $this->root().'/operations/receipts/'.$id;
    }

    private function syncDirectory(string $path): void
    {
        $directory = fopen($path, 'r');
        try {
            if (! fsync($directory)) {
                throw new RuntimeException('Unable to persist operation directory.');
            }
        } finally {
            fclose($directory);
        }
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $this->safeFile($path);
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        $mask = umask(0077);
        $stream = fopen($temporary, 'x');
        umask($mask);
        try {
            if (! $stream || fwrite($stream, $contents) !== strlen($contents) || ! fflush($stream) || ! fsync($stream) || ! rename($temporary, $path)) {
                throw new RuntimeException('Unable to persist operation.');
            }
            $directory = fopen(dirname($path), 'r');
            try {
                if (! fsync($directory)) {
                    throw new RuntimeException('Unable to persist operation directory.');
                }
            } finally {
                fclose($directory);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }
            $value = array_map($this->canonical(...), $value);
        }

        return $value;
    }
}
