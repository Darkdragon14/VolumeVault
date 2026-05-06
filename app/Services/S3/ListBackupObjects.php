<?php

namespace App\Services\S3;

use App\Models\BackupDestination;

class ListBackupObjects
{
    public function __construct(private readonly S3ClientFactory $clientFactory) {}

    public function handle(BackupDestination $destination): array
    {
        $result = $this->clientFactory->make($destination)->listObjectsV2([
            'Bucket' => $destination->bucket,
            'Prefix' => trim((string) $destination->path_prefix, '/'),
            'MaxKeys' => 1000,
        ]);

        $objects = collect($result['Contents'] ?? [])
            ->filter(fn (array $object) => $this->plausibleBackupKey((string) ($object['Key'] ?? '')))
            ->map(fn (array $object) => [
                'key' => (string) $object['Key'],
                'size' => (int) ($object['Size'] ?? 0),
                'last_modified' => isset($object['LastModified']) ? $object['LastModified']->format(DATE_ATOM) : null,
            ])
            ->sortByDesc('last_modified')
            ->values()
            ->all();

        return $objects;
    }

    private function plausibleBackupKey(string $key): bool
    {
        return filled($key) && preg_match('/\.(tar|tar\.gz|tgz|tar\.zst|gz)$/i', $key) === 1;
    }
}
