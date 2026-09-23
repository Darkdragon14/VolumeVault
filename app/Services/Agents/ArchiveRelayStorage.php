<?php

namespace App\Services\Agents;

use App\Models\ArchiveRelay;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ArchiveRelayStorage
{
    public const CHUNK_BYTES = 1048576;

    public function directory(string $id): string
    {
        if (! Str::isUuid($id) || strtolower($id) !== $id) {
            throw new RuntimeException('Invalid relay identity.');
        }

        return rtrim(config('volumevault.archive_relay.directory'), '/').'/'.$id;
    }

    public function upload(ArchiveRelay $relay, int $offset, string $data, int $size, string $sha256): int
    {
        return DB::transaction(function () use ($relay, $offset, $data, $size, $sha256): int {
            $relay = ArchiveRelay::query()->lockForUpdate()->findOrFail($relay->id);
            abort_if($relay->expires_at->isPast(), 410, 'Archive relay expired.');
            abort_unless(in_array($relay->status, ['pending', 'exporting'], true), 409, 'Relay is not accepting data.');
            abort_unless($size > 0 && $size <= $relay->reserved_bytes && preg_match('/\A[0-9a-f]{64}\z/', $sha256), 422, 'Invalid archive size or digest.');
            abort_unless($offset >= 0 && $offset % self::CHUNK_BYTES === 0 && $offset < $size
                && strlen($data) === min(self::CHUNK_BYTES, $size - $offset), 422, 'Invalid relay chunk.');
            abort_if($relay->size_bytes !== null && ((int) $relay->size_bytes !== $size || $relay->sha256 !== $sha256), 409, 'Archive identity changed.');
            abort_if($offset > $relay->uploaded_bytes, 409, 'Unexpected relay offset.');
            $directory = $this->directory($relay->id);
            $this->secureDirectory(dirname($directory));
            $this->secureDirectory($directory);
            $path = $directory.'/'.$offset;
            $this->assertRegular($path);
            if (is_file($path)) {
                abort_unless(hash_equals(hash('sha256', $this->read($relay, $offset)), hash('sha256', $data)), 409, 'Conflicting relay retry.');
            } else {
                abort_unless($offset === (int) $relay->uploaded_bytes, 409, 'Relay chunk is missing.');
                abort_unless(disk_free_space($directory) > 2 * self::CHUNK_BYTES, 422, 'Archive relay spool disk space is exhausted.');
                $this->publish($path, Crypt::encryptString($data));
            }
            $relay->forceFill(['status' => 'exporting', 'size_bytes' => $size, 'sha256' => $sha256,
                'error_message' => null,
                'uploaded_bytes' => max((int) $relay->uploaded_bytes, $offset + strlen($data))])->save();

            return (int) $relay->uploaded_bytes;
        });
    }

    public function verify(ArchiveRelay $relay): void
    {
        abort_unless($relay->size_bytes > 0 && $relay->uploaded_bytes === $relay->size_bytes, 409, 'Archive upload is incomplete.');
        $hash = hash_init('sha256');
        for ($offset = 0; $offset < $relay->size_bytes; $offset += self::CHUNK_BYTES) {
            $chunk = $this->read($relay, $offset);
            abort_unless(strlen($chunk) === min(self::CHUNK_BYTES, $relay->size_bytes - $offset), 409, 'Archive chunk is incomplete.');
            hash_update($hash, $chunk);
        }
        abort_unless(hash_equals($relay->sha256, hash_final($hash)), 409, 'Archive digest does not match.');
    }

    public function read(ArchiveRelay $relay, int $offset): string
    {
        abort_unless($offset >= 0 && $offset % self::CHUNK_BYTES === 0, 422, 'Invalid relay offset.');
        $directory = $this->directory($relay->id);
        $this->assertRegular(dirname($directory));
        $this->assertRegular($directory);
        $path = $directory.'/'.$offset;
        $this->assertRegular($path);
        if (! is_file($path) || filesize($path) > 2 * self::CHUNK_BYTES) {
            throw new RuntimeException('Relay chunk is unavailable.');
        }

        return Crypt::decryptString(file_get_contents($path));
    }

    public function secureDirectory(string $path): void
    {
        if (is_link($path) || (! is_dir($path) && ! mkdir($path, 0700, true)) || ! chmod($path, 0700)) {
            throw new RuntimeException('Unable to secure relay storage.');
        }
    }

    private function assertRegular(string $path): void
    {
        if (is_link($path) || (file_exists($path) && ! is_file($path) && ! is_dir($path))) {
            throw new RuntimeException('Unsafe relay storage.');
        }
    }

    private function publish(string $path, string $contents): void
    {
        $mask = umask(0077);
        $temporary = $path.'.tmp';
        $this->assertRegular($temporary);
        $stream = fopen($temporary, 'wb');
        umask($mask);
        try {
            if (! $stream || ! chmod($temporary, 0600) || fwrite($stream, $contents) !== strlen($contents)
                || ! fflush($stream) || ! fsync($stream) || ! rename($temporary, $path)) {
                throw new RuntimeException('Unable to persist relay chunk.');
            }
            $directory = fopen(dirname($path), 'r');
            try {
                if (! fsync($directory)) {
                    throw new RuntimeException('Unable to persist relay directory.');
                }
            } finally {
                fclose($directory);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
