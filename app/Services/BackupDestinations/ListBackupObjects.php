<?php

namespace App\Services\BackupDestinations;

use App\Models\BackupDestination;
use App\Models\BackupRun;
use Illuminate\Validation\ValidationException;

class ListBackupObjects
{
    public const UNVERIFIABLE_RUN_MESSAGE = 'This historical Dropbox backup has no stable file ID. Its identity cannot be verified, so restoring this run is unavailable.';

    public static function isRunUnverifiable(BackupDestination $destination, ?BackupRun $run): bool
    {
        return $run !== null
            && $destination->provider === BackupDestination::PROVIDER_DROPBOX
            && ! str_starts_with((string) $run->backup_key, 'id:');
    }

    public function __construct(private readonly DestinationStorage $storage) {}

    public function handle(BackupDestination $destination): array
    {
        return $this->storage->listBackupObjects($destination);
    }

    public function handleForRun(BackupDestination $destination, ?BackupRun $run): array
    {
        if (self::isRunUnverifiable($destination, $run)) {
            throw ValidationException::withMessages(['selected_backup_key' => self::UNVERIFIABLE_RUN_MESSAGE]);
        }

        $objects = $run !== null && $destination->provider === BackupDestination::PROVIDER_DROPBOX
            ? []
            : $this->handle($destination);
        $key = $run?->backup_key;

        if (blank($key) || collect($objects)->contains(fn (array $object): bool => ($object['key'] ?? null) === $key)) {
            return $objects;
        }

        $resolved = $this->storage->findBackupObjectByKey($destination, $key);

        if ($resolved === null || ($resolved['key'] ?? null) !== $key) {
            return $objects;
        }

        $objects[] = [
            ...$resolved,
            'key' => $key,
            'display_name' => $resolved['display_name'] ?? ($run->backup_filename ?: $key),
            'size' => $resolved['size'] ?? $run->backup_size_bytes,
            'last_modified' => $resolved['last_modified'] ?? $run->finished_at?->toAtomString(),
        ];

        return $objects;
    }

    public function contains(BackupDestination $destination, string $key, bool $exhaustive = false): bool
    {
        if ($exhaustive) {
            return $this->storage->hasBackupObject($destination, $key);
        }

        return collect($this->handle($destination))->contains(
            fn (array $object): bool => ($object['key'] ?? null) === $key,
        );
    }

    /** @return array<string, mixed>|null */
    public function findByFilename(BackupDestination $destination, string $filename): ?array
    {
        return $this->storage->findBackupObjectByFilename($destination, $filename);
    }
}
