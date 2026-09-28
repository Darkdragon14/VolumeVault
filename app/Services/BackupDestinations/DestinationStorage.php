<?php

namespace App\Services\BackupDestinations;

use App\Actions\Docker\RunBackupContainer;
use App\Models\BackupDestination;
use App\Services\BackupSources\HostPathPolicy;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerVolumeName;
use App\Services\Docker\LocalDockerExecution;
use App\Services\S3\S3ClientFactory;
use App\Services\Security\OutboundHostGuard;
use App\Support\SshHostKey;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use RuntimeException;
use Throwable;

class DestinationStorage
{
    private ?string $operationHelperName = null;

    private ?int $operationMaxBytes = null;

    public function useOperationLimit(?int $bytes): void
    {
        $this->operationMaxBytes = $bytes;
    }

    public function useOperationHelper(?string $name): void
    {
        $this->operationHelperName = $name;
    }

    /** phpseclib's NET_SFTP_TYPE_DIRECTORY — only defined once an SFTP instance is constructed, so we mirror it here. */
    private const SFTP_TYPE_DIRECTORY = 2;

    /** phpseclib's NET_SFTP_TYPE_REGULAR. */
    private const SFTP_TYPE_REGULAR = 1;

    private readonly OutboundHostGuard $outboundHostGuard;

    private readonly DockerProcess $dockerProcess;

    private readonly SecureLocalArchiveReader $secureLocalArchiveReader;

    private readonly HostPathPolicy $hostPathPolicy;

    public function __construct(
        private readonly S3ClientFactory $s3ClientFactory,
        ?OutboundHostGuard $outboundHostGuard = null,
        ?DockerProcess $dockerProcess = null,
        ?SecureLocalArchiveReader $secureLocalArchiveReader = null,
        ?HostPathPolicy $hostPathPolicy = null,
    ) {
        $this->outboundHostGuard = $outboundHostGuard ?? new OutboundHostGuard;
        $this->dockerProcess = $dockerProcess ?? new DockerProcess;
        $this->secureLocalArchiveReader = $secureLocalArchiveReader ?? new SecureLocalArchiveReader;
        $this->hostPathPolicy = $hostPathPolicy ?? new HostPathPolicy;
    }

    public function test(BackupDestination $destination): void
    {
        $this->guardOutbound($destination);

        match ($destination->provider) {
            BackupDestination::PROVIDER_AWS_S3,
            BackupDestination::PROVIDER_CLOUDFLARE_R2,
            BackupDestination::PROVIDER_CUSTOM_S3 => $this->listS3($destination, 1),
            BackupDestination::PROVIDER_WEBDAV => $this->listWebDav($destination, 1),
            BackupDestination::PROVIDER_SSH => $this->listSftp($destination, 1),
            BackupDestination::PROVIDER_AZURE_BLOB => $this->listAzure($destination, 1),
            BackupDestination::PROVIDER_DROPBOX => $this->listDropbox($destination, 1),
            BackupDestination::PROVIDER_GOOGLE_DRIVE => $this->listGoogleDrive($destination, 1),
            BackupDestination::PROVIDER_LOCAL => $this->testLocal($destination),
            BackupDestination::PROVIDER_DOCKER_VOLUME => $this->testDockerVolume($destination),
            default => throw new RuntimeException('Unsupported backup destination provider.'),
        };
    }

    public function testReadOnly(BackupDestination $destination): void
    {
        if ($destination->provider === BackupDestination::PROVIDER_DOCKER_VOLUME) {
            $this->guardOutbound($destination);
            $this->listDockerVolume($destination, 1);

            return;
        }
        $this->test($destination);
    }

    public function listBackupObjects(BackupDestination $destination): array
    {
        $this->guardOutbound($destination);

        $objects = match ($destination->provider) {
            BackupDestination::PROVIDER_AWS_S3,
            BackupDestination::PROVIDER_CLOUDFLARE_R2,
            BackupDestination::PROVIDER_CUSTOM_S3 => $this->listS3($destination),
            BackupDestination::PROVIDER_WEBDAV => $this->listWebDav($destination),
            BackupDestination::PROVIDER_SSH => $this->listSftp($destination),
            BackupDestination::PROVIDER_AZURE_BLOB => $this->listAzure($destination),
            BackupDestination::PROVIDER_DROPBOX => $this->listDropbox($destination),
            BackupDestination::PROVIDER_GOOGLE_DRIVE => $this->listGoogleDrive($destination),
            BackupDestination::PROVIDER_LOCAL => $this->listLocal($destination),
            BackupDestination::PROVIDER_DOCKER_VOLUME => $this->listDockerVolume($destination),
            default => throw new RuntimeException('Unsupported backup destination provider.'),
        };

        return collect($objects)
            ->filter(fn (array $object) => $this->plausibleBackupKey((string) ($object['display_name'] ?? $object['key'] ?? '')))
            ->sortByDesc('last_modified')
            ->values()
            ->all();
    }

    public function hasBackupObject(BackupDestination $destination, string $key): bool
    {
        $this->guardOutbound($destination);

        return match ($destination->provider) {
            BackupDestination::PROVIDER_AWS_S3,
            BackupDestination::PROVIDER_CLOUDFLARE_R2,
            BackupDestination::PROVIDER_CUSTOM_S3 => $this->hasS3Object($destination, $key),
            BackupDestination::PROVIDER_WEBDAV => $this->hasWebDavObject($destination, $key),
            BackupDestination::PROVIDER_SSH => $this->hasSftpObject($destination, $key),
            BackupDestination::PROVIDER_AZURE_BLOB => $this->hasAzureObject($destination, $key),
            BackupDestination::PROVIDER_DROPBOX => $this->hasDropboxObject($destination, $key),
            BackupDestination::PROVIDER_GOOGLE_DRIVE => $this->hasGoogleDriveObject($destination, $key),
            BackupDestination::PROVIDER_LOCAL => $this->hasLocalObject($destination, $key),
            BackupDestination::PROVIDER_DOCKER_VOLUME => $this->hasDockerVolumeObject($destination, $key),
            default => throw new RuntimeException('Unsupported backup destination provider.'),
        };
    }

    /** @return array<string, mixed>|null */
    public function findBackupObjectByFilename(BackupDestination $destination, string $filename): ?array
    {
        $this->guardOutbound($destination);

        if (! $this->plausibleBackupKey($filename)) {
            return null;
        }

        if ($destination->provider === BackupDestination::PROVIDER_GOOGLE_DRIVE) {
            return $this->findGoogleDriveObjectByFilename($destination, $filename);
        }

        $key = match ($destination->provider) {
            BackupDestination::PROVIDER_AWS_S3,
            BackupDestination::PROVIDER_CLOUDFLARE_R2,
            BackupDestination::PROVIDER_CUSTOM_S3 => $this->joinRelative($destination->setting('path_prefix'), $filename),
            BackupDestination::PROVIDER_DROPBOX => $this->dropboxPath($destination, filename: $filename),
            default => $filename,
        };

        $listed = collect($this->listBackupObjects($destination))->first(function (array $object) use ($destination, $filename, $key): bool {
            if (($object['key'] ?? null) === $key) {
                return true;
            }

            return $destination->provider === BackupDestination::PROVIDER_DROPBOX
                && ($object['display_name'] ?? null) === $filename;
        });

        if ($listed !== null) {
            return $listed;
        }

        $resolved = $this->findBackupObjectByKey($destination, $key);

        return $resolved === null ? null : [...$resolved, 'display_name' => $resolved['display_name'] ?? $filename];
    }

    /** @return array<string, mixed>|null */
    public function findBackupObjectByKey(BackupDestination $destination, string $key): ?array
    {
        $this->guardOutbound($destination);

        if ($destination->provider === BackupDestination::PROVIDER_DROPBOX) {
            return $this->dropboxObjectMetadata($destination, $key);
        }

        return $this->hasBackupObject($destination, $key) ? ['key' => $key] : null;
    }

    /** @return array{used_bytes: int, object_count: int} */
    public function storageUsage(BackupDestination $destination): array
    {
        if ($destination->storageMeasurementHostId() !== \App\Models\DockerHost::LOCAL_ID) {
            return app(DestinationOperations::class)->usage($destination);
        }
        LocalDockerExecution::assertDestination($destination);
        $cacheKey = 'destination_storage_usage_bytes_'.$destination->id;
        $fingerprint = $destination->storageMeasurementFingerprint();
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && ($cached['fingerprint'] ?? null) === $fingerprint) {
            return $cached['usage'];
        }
        $usage = $this->aggregateUsage($destination);
        Cache::put($cacheKey, ['fingerprint' => $fingerprint, 'usage' => $usage], now()->addMinutes(30));

        return $usage;
    }

    public function freshStorageUsage(BackupDestination $destination): array
    {
        return $this->aggregateUsage($destination);
    }

    /** Pages contain at most 1000 objects; provider tokens never enter diagnostics. */
    public function listBackupObjectsPage(BackupDestination $destination, ?string $cursor = null, int $limit = 1000): array
    {
        $this->guardOutbound($destination);
        if ($limit < 1 || $limit > 1000) {
            throw new RuntimeException('Invalid listing page size.');
        }
        if (in_array($destination->provider, BackupDestination::S3_PROVIDERS, true)) {
            $params = ['Bucket' => $destination->setting('bucket'), 'Prefix' => trim((string) $destination->setting('path_prefix'), '/'), 'MaxKeys' => $limit];
            if ($cursor !== null) {
                $params['ContinuationToken'] = $cursor;
            }
            $page = $this->s3ClientFactory->make($destination)->listObjectsV2($params);
            $objects = [];
            foreach ($page['Contents'] ?? [] as $object) {
                $objects[] = ['key' => (string) $object['Key'], 'display_name' => (string) $object['Key'], 'size' => (int) ($object['Size'] ?? 0), 'last_modified' => isset($object['LastModified']) ? $object['LastModified']->format(DATE_ATOM) : null];
            }
            $next = ($page['IsTruncated'] ?? false) ? ($page['NextContinuationToken'] ?? null) : null;
        } elseif (in_array($destination->provider, [BackupDestination::PROVIDER_AZURE_BLOB, BackupDestination::PROVIDER_GOOGLE_DRIVE, BackupDestination::PROVIDER_DROPBOX], true)) {
            [$objects, $next] = $this->cloudObjectPage($destination, $cursor, $limit);
        } else {
            if ($cursor !== null && (! ctype_digit($cursor) || strlen($cursor) > 7)) {
                throw new RuntimeException('Invalid listing cursor.');
            }
            $offset = (int) ($cursor ?? 0);
            $count = $offset + $limit + 1;
            $all = match ($destination->provider) {
                BackupDestination::PROVIDER_WEBDAV => $this->listWebDav($destination, $count),
                BackupDestination::PROVIDER_SSH => $this->listSftp($destination, $count),
                BackupDestination::PROVIDER_AZURE_BLOB => $this->listAzure($destination, $count),
                BackupDestination::PROVIDER_DROPBOX => $this->listDropbox($destination, $count),
                BackupDestination::PROVIDER_GOOGLE_DRIVE => $this->listGoogleDrive($destination, $count),
                BackupDestination::PROVIDER_LOCAL => $this->listLocal($destination, $count),
                BackupDestination::PROVIDER_DOCKER_VOLUME => $this->listDockerVolume($destination, $count),
                default => throw new RuntimeException('Unsupported destination.'),
            };
            $next = count($all) > $offset + $limit ? (string) ($offset + $limit) : null;
            $objects = array_slice($all, $offset, $limit);
        }

        return ['objects' => array_values(array_filter($objects, fn (array $object): bool => $this->plausibleBackupKey($object['display_name']))), 'next_cursor' => $next];
    }

    /** @return array{array, ?string} */
    private function cloudObjectPage(BackupDestination $destination, ?string $cursor, int $limit): array
    {
        $objects = [];
        if ($destination->provider === BackupDestination::PROVIDER_AZURE_BLOB) {
            $query = ['restype' => 'container', 'comp' => 'list', 'maxresults' => (string) $limit];
            if ($cursor !== null) {
                $query['marker'] = $cursor;
            }
            $xml = simplexml_load_string($this->azureContainerRequest($destination, 'GET', $query)->body());
            if ($xml === false) {
                throw new RuntimeException('Invalid Azure listing.');
            }
            foreach ($xml->Blobs->Blob ?? [] as $blob) {
                $objects[] = ['key' => (string) $blob->Name, 'display_name' => (string) $blob->Name, 'size' => (int) $blob->Properties->{'Content-Length'}, 'last_modified' => date(DATE_ATOM, strtotime((string) $blob->Properties->{'Last-Modified'}))];
            }

            return [$objects, ((string) ($xml->NextMarker ?? '')) ?: null];
        }
        if ($destination->provider === BackupDestination::PROVIDER_GOOGLE_DRIVE) {
            $query = [
                'q' => "'".$destination->setting('folder_id')."' in parents and trashed = false",
                'fields' => 'nextPageToken,files(id,name,size,modifiedTime,mimeType)', 'orderBy' => 'modifiedTime desc',
                'pageSize' => $limit, 'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true',
            ];
            if ($cursor !== null) {
                $query['pageToken'] = $cursor;
            }
            $response = Http::withToken($this->googleDriveToken($destination))->get($this->googleDriveEndpoint($destination).'/files', $query);
            if ($response->failed()) {
                throw new RuntimeException('Google Drive listing failed.');
            }
            foreach ($response->json('files') ?? [] as $file) {
                if (($file['mimeType'] ?? null) === 'application/vnd.google-apps.folder') {
                    continue;
                }
                $objects[] = ['key' => (string) $file['id'], 'display_name' => (string) $file['name'], 'size' => (int) ($file['size'] ?? 0), 'last_modified' => $file['modifiedTime'] ?? null];
            }

            return [$objects, $response->json('nextPageToken') ?: null];
        }
        $request = Http::withToken($this->dropboxToken($destination));
        $response = $cursor === null
            ? $request->post('https://api.dropboxapi.com/2/files/list_folder', ['path' => $this->dropboxPath($destination), 'recursive' => true, 'include_deleted' => false, 'limit' => $limit])
            : $request->post('https://api.dropboxapi.com/2/files/list_folder/continue', ['cursor' => $cursor]);
        $this->ensureDropboxOk($response);
        foreach ($response->json('entries') ?? [] as $entry) {
            if (($entry['.tag'] ?? null) !== 'file' || ! is_string($entry['id'] ?? null) || ! str_starts_with($entry['id'], 'id:')) {
                continue;
            }
            $name = $this->dropboxDisplayName($destination, $entry);
            if ($name !== null) {
                $objects[] = ['key' => $entry['id'], 'display_name' => $name, 'size' => (int) ($entry['size'] ?? 0), 'last_modified' => $entry['server_modified'] ?? null];
            }
        }

        return [$objects, $response->json('has_more') ? $response->json('cursor') : null];
    }

    /**
     * Sum sizes and count objects without holding the full listing in memory.
     *
     * @return array{used_bytes: int, object_count: int}
     */
    private function aggregateUsage(BackupDestination $destination): array
    {
        $this->guardOutbound($destination);

        // The Docker volume provider aggregates size and count inside the helper
        // container (a single "bytes|count" line), so a volume with very many
        // files never streams a full listing back into the process buffer.
        if ($destination->provider === BackupDestination::PROVIDER_DOCKER_VOLUME) {
            return $this->dockerVolumeUsage($destination);
        }

        $usedBytes = 0;
        $objectCount = 0;
        $accumulate = function (array $object) use (&$usedBytes, &$objectCount): void {
            $usedBytes += (int) ($object['size'] ?? 0);
            $objectCount++;
        };

        match ($destination->provider) {
            BackupDestination::PROVIDER_AWS_S3,
            BackupDestination::PROVIDER_CLOUDFLARE_R2,
            BackupDestination::PROVIDER_CUSTOM_S3 => $this->streamS3($destination, $accumulate),
            BackupDestination::PROVIDER_SSH => $this->streamSftp($destination, $accumulate),
            default => collect($this->listAllObjects($destination))->each($accumulate),
        };

        return [
            'used_bytes' => $usedBytes,
            'object_count' => $objectCount,
        ];
    }

    public function upload(BackupDestination $destination, string $sourcePath, string $filename, ?string $directory = null): string
    {
        $this->guardOutbound($destination);

        return match ($destination->provider) {
            BackupDestination::PROVIDER_AWS_S3,
            BackupDestination::PROVIDER_CLOUDFLARE_R2,
            BackupDestination::PROVIDER_CUSTOM_S3 => $this->uploadS3($destination, $sourcePath, $filename, $directory),
            BackupDestination::PROVIDER_WEBDAV => $this->uploadWebDav($destination, $sourcePath, $filename, $directory),
            BackupDestination::PROVIDER_SSH => $this->uploadSftp($destination, $sourcePath, $filename, $directory),
            BackupDestination::PROVIDER_AZURE_BLOB => $this->uploadAzure($destination, $sourcePath, $filename),
            BackupDestination::PROVIDER_DROPBOX => $this->uploadDropbox($destination, $sourcePath, $filename, $directory),
            BackupDestination::PROVIDER_GOOGLE_DRIVE => $this->uploadGoogleDrive($destination, $sourcePath, $filename),
            BackupDestination::PROVIDER_LOCAL => $this->uploadLocal($destination, $sourcePath, $filename, $directory),
            BackupDestination::PROVIDER_DOCKER_VOLUME => $this->uploadDockerVolume($destination, $sourcePath, $filename, $directory),
            default => throw new RuntimeException('Unsupported backup destination provider.'),
        };
    }

    public function download(BackupDestination $destination, string $key, string $targetPath, ?callable $progress = null): void
    {
        $this->guardOutbound($destination);
        $progress = $this->downloadProgress($progress);

        match ($destination->provider) {
            BackupDestination::PROVIDER_AWS_S3,
            BackupDestination::PROVIDER_CLOUDFLARE_R2,
            BackupDestination::PROVIDER_CUSTOM_S3 => $this->downloadS3($destination, $key, $targetPath, $progress),
            BackupDestination::PROVIDER_WEBDAV => $this->downloadWebDav($destination, $key, $targetPath, $progress),
            BackupDestination::PROVIDER_SSH => $this->downloadSftp($destination, $key, $targetPath, $progress),
            BackupDestination::PROVIDER_AZURE_BLOB => $this->downloadAzure($destination, $key, $targetPath, $progress),
            BackupDestination::PROVIDER_DROPBOX => $this->downloadDropbox($destination, $key, $targetPath, $progress),
            BackupDestination::PROVIDER_GOOGLE_DRIVE => $this->downloadGoogleDrive($destination, $key, $targetPath, $progress),
            BackupDestination::PROVIDER_LOCAL => $this->downloadLocal($destination, $key, $targetPath, $progress),
            BackupDestination::PROVIDER_DOCKER_VOLUME => $this->downloadDockerVolume($destination, $key, $targetPath, $progress),
            default => throw new RuntimeException('Unsupported backup destination provider.'),
        };
    }

    private function downloadProgress(?callable $progress): ?callable
    {
        if ($progress === null) {
            return null;
        }

        $lastProgressAt = microtime(true);
        $progress();

        return function () use ($progress, &$lastProgressAt): void {
            $now = microtime(true);

            if ($now - $lastProgressAt < 30) {
                return;
            }

            $lastProgressAt = $now;
            $progress();
        };
    }

    private function listAllObjects(BackupDestination $destination): array
    {
        return match ($destination->provider) {
            BackupDestination::PROVIDER_AWS_S3,
            BackupDestination::PROVIDER_CLOUDFLARE_R2,
            BackupDestination::PROVIDER_CUSTOM_S3 => $this->listS3($destination, PHP_INT_MAX),
            BackupDestination::PROVIDER_WEBDAV => $this->listWebDav($destination, PHP_INT_MAX),
            BackupDestination::PROVIDER_SSH => $this->listSftp($destination, PHP_INT_MAX),
            BackupDestination::PROVIDER_AZURE_BLOB => $this->listAzure($destination, PHP_INT_MAX),
            BackupDestination::PROVIDER_DROPBOX => $this->listDropbox($destination, PHP_INT_MAX),
            BackupDestination::PROVIDER_GOOGLE_DRIVE => $this->listGoogleDrive($destination, PHP_INT_MAX),
            BackupDestination::PROVIDER_LOCAL => $this->listLocal($destination, PHP_INT_MAX),
            BackupDestination::PROVIDER_DOCKER_VOLUME => $this->listDockerVolume($destination, PHP_INT_MAX),
            default => throw new RuntimeException('Unsupported backup destination provider.'),
        };
    }

    private function listS3(BackupDestination $destination, int $maxKeys = 1000): array
    {
        $objects = [];
        $this->streamS3($destination, function (array $object) use (&$objects): void {
            $objects[] = $object;
        }, $maxKeys);

        return $objects;
    }

    /**
     * Page through the bucket and hand each object to $onObject without buffering the whole listing.
     */
    private function streamS3(BackupDestination $destination, callable $onObject, int $maxKeys = PHP_INT_MAX): void
    {
        $client = $this->s3ClientFactory->make($destination);
        $prefix = trim((string) $destination->setting('path_prefix'), '/');
        $emitted = 0;
        $continuationToken = null;

        do {
            $remaining = $maxKeys - $emitted;
            $params = [
                'Bucket' => $destination->setting('bucket'),
                'Prefix' => $prefix,
                'MaxKeys' => min(max($remaining, 1), 1000),
            ];

            if ($continuationToken) {
                $params['ContinuationToken'] = $continuationToken;
            }

            $result = $client->listObjectsV2($params);

            foreach ($result['Contents'] ?? [] as $object) {
                if ($emitted >= $maxKeys) {
                    break;
                }

                $onObject([
                    'key' => (string) $object['Key'],
                    'display_name' => (string) $object['Key'],
                    'size' => (int) ($object['Size'] ?? 0),
                    'last_modified' => isset($object['LastModified']) ? $object['LastModified']->format(DATE_ATOM) : null,
                ]);
                $emitted++;
            }

            $continuationToken = ($result['IsTruncated'] ?? false) ? (string) ($result['NextContinuationToken'] ?? '') : null;
        } while ($continuationToken && $emitted < $maxKeys);
    }

    private function uploadS3(BackupDestination $destination, string $sourcePath, string $filename, ?string $directory): string
    {
        $key = $this->joinRelative($destination->setting('path_prefix'), $directory, $filename);

        $this->s3ClientFactory->make($destination)->putObject([
            'Bucket' => $destination->setting('bucket'),
            'Key' => $key,
            'SourceFile' => $sourcePath,
        ]);

        return $key;
    }

    private function downloadS3(BackupDestination $destination, string $key, string $targetPath, ?callable $progress): void
    {
        $options = [
            'Bucket' => $destination->setting('bucket'),
            'Key' => $key,
            'SaveAs' => $targetPath,
        ];

        if ($progress !== null) {
            $options['@http'] = ['progress' => $progress];
        }

        $this->s3ClientFactory->make($destination)->getObject($options);
    }

    private function hasS3Object(BackupDestination $destination, string $key): bool
    {
        try {
            $this->s3ClientFactory->make($destination)->headObject([
                'Bucket' => $destination->setting('bucket'),
                'Key' => $key,
            ]);

            return true;
        } catch (Throwable $exception) {
            if (method_exists($exception, 'getStatusCode') && $exception->getStatusCode() === 404) {
                return false;
            }

            throw $exception;
        }
    }

    private function listWebDav(BackupDestination $destination, int $limit = 1000): array
    {
        $basePath = $this->configuredWebDavPath($destination);
        $baseUrl = $this->webDavUrl($destination, $basePath);
        $response = $this->webDavRequest($destination, 'PROPFIND', $baseUrl, [
            'headers' => ['Depth' => 'infinity', 'Content-Type' => 'application/xml; charset=utf-8'],
            'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:getcontentlength/><d:getlastmodified/><d:resourcetype/></d:prop></d:propfind>',
        ]);

        $xml = simplexml_load_string($response->body());

        if ($xml === false) {
            throw new RuntimeException('Unable to parse WebDAV response.');
        }

        $objects = [];
        $baseUrlPath = rtrim($this->decodeWebDavUrlPath((string) parse_url($baseUrl, PHP_URL_PATH)), '/');

        foreach ($xml->children('DAV:')->response as $entry) {
            $dav = $entry->children('DAV:');
            $hrefUrlPath = (string) parse_url((string) $dav->href, PHP_URL_PATH);

            try {
                $hrefPath = $this->decodeWebDavUrlPath($hrefUrlPath);
                $prefix = $baseUrlPath === '' ? '/' : $baseUrlPath.'/';

                if (! str_starts_with($hrefPath, $prefix)) {
                    continue;
                }

                $relative = $this->assertWebDavKey(substr($hrefPath, strlen($prefix)));
            } catch (RuntimeException) {
                continue;
            }

            if (count($objects) >= $limit) {
                break;
            }

            $props = null;
            foreach ($dav->propstat as $propstat) {
                $props = $propstat->children('DAV:')->prop->children('DAV:');
                break;
            }

            if (! $props || $props->resourcetype->children('DAV:')->count() > 0) {
                continue;
            }

            $lastModified = strtotime((string) $props->getlastmodified);
            $objects[] = [
                'key' => $relative,
                'display_name' => $relative,
                'size' => (int) $props->getcontentlength,
                'last_modified' => $lastModified ? date(DATE_ATOM, $lastModified) : null,
            ];
        }

        return $objects;
    }

    private function uploadWebDav(BackupDestination $destination, string $sourcePath, string $filename, ?string $directory): string
    {
        $key = $this->assertWebDavKey($this->joinRelative($directory, $filename));
        $this->ensureWebDavDirectory($destination, dirname($key));
        $remotePath = $this->joinRelative($this->configuredWebDavPath($destination), $key);
        $this->webDavRequest($destination, 'PUT', $this->webDavUrl($destination, $remotePath), [
            'headers' => ['Content-Type' => 'application/octet-stream'],
            'body' => File::get($sourcePath),
        ]);

        return $key;
    }

    private function downloadWebDav(BackupDestination $destination, string $key, string $targetPath, ?callable $progress): void
    {
        $remotePath = $this->joinRelative($this->configuredWebDavPath($destination), $this->assertWebDavKey($key));
        $options = ['sink' => $targetPath];

        if ($progress !== null) {
            $options['progress'] = $progress;
        }

        $this->webDavRequest($destination, 'GET', $this->webDavUrl($destination, $remotePath), $options);
    }

    private function hasWebDavObject(BackupDestination $destination, string $key): bool
    {
        $remotePath = $this->joinRelative($this->configuredWebDavPath($destination), $this->assertWebDavKey($key));
        $response = $this->webDavRequest($destination, 'HEAD', $this->webDavUrl($destination, $remotePath), [
            'allowed_statuses' => [404],
        ]);

        return $response->status() !== 404;
    }

    private function webDavRequest(BackupDestination $destination, string $method, string $url, array $options = []): Response
    {
        $allowedStatuses = $options['allowed_statuses'] ?? [];
        unset($options['allowed_statuses']);

        $request = Http::withOptions(['verify' => ! (bool) $destination->setting('insecure', false)]);

        if (filled($destination->secret('username')) || filled($destination->secret('password'))) {
            $request = $request->withBasicAuth((string) $destination->secret('username'), (string) $destination->secret('password'));
        }

        $response = $request->send($method, $url, $options);

        if ($response->failed() && ! in_array($response->status(), $allowedStatuses, true)) {
            throw new RuntimeException('WebDAV request failed with HTTP '.$response->status().'.');
        }

        return $response;
    }

    private function ensureWebDavDirectory(BackupDestination $destination, string $path): void
    {
        $path = $path === '.' ? '' : trim($path, '/');
        $path = $this->joinRelative($this->configuredWebDavPath($destination), $path);

        if ($path === '') {
            return;
        }

        $current = '';
        foreach (explode('/', $path) as $segment) {
            $current = $this->joinRelative($current, $segment);
            $response = $this->webDavRequest($destination, 'MKCOL', $this->webDavUrl($destination, $current), [
                'allowed_statuses' => [405],
            ]);

            if (! in_array($response->status(), [200, 201, 204, 405], true)) {
                throw new RuntimeException('Unable to create WebDAV directory.');
            }
        }
    }

    private function webDavUrl(BackupDestination $destination, string $path = ''): string
    {
        $encodedPath = collect(explode('/', trim($path, '/')))
            ->filter(fn (string $segment): bool => $segment !== '')
            ->map(fn (string $segment): string => rawurlencode($segment))
            ->implode('/');

        return rtrim((string) $destination->setting('url'), '/').($encodedPath === '' ? '' : '/'.$encodedPath);
    }

    private function configuredWebDavPath(BackupDestination $destination): string
    {
        $path = trim((string) $destination->setting('path'), '/');

        if ($path === '') {
            return '';
        }

        return collect(explode('/', $path))
            ->map(function (string $segment): string {
                if (
                    $segment === ''
                    || $segment === '.'
                    || $segment === '..'
                    || strlen($segment) > 255
                    || str_contains($segment, '\\')
                    || preg_match('/[\x00-\x1F\x7F]/', $segment) === 1
                ) {
                    throw new RuntimeException('Invalid WebDAV destination path.');
                }

                return $segment;
            })
            ->implode('/');
    }

    private function decodeWebDavUrlPath(string $path): string
    {
        if (str_contains($path, "\0") || preg_match('/%(?:2f|5c|00)/i', $path) === 1) {
            throw new RuntimeException('Ambiguous WebDAV URL path.');
        }

        $decoded = rawurldecode($path);

        if (str_contains($decoded, "\0") || str_contains($decoded, '\\')) {
            throw new RuntimeException('Ambiguous WebDAV URL path.');
        }

        return $decoded;
    }

    private function assertWebDavKey(string $key): string
    {
        if (
            $key === ''
            || strlen($key) > 1024
            || str_starts_with($key, '/')
            || str_contains($key, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $key) === 1
        ) {
            throw new RuntimeException('Invalid WebDAV object key.');
        }

        foreach (explode('/', $key) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || strlen($segment) > 255) {
                throw new RuntimeException('Invalid WebDAV object key.');
            }
        }

        return $key;
    }

    private function listSftp(BackupDestination $destination, int $limit = 1000): array
    {
        $objects = [];
        $this->streamSftp($destination, function (array $object) use (&$objects): void {
            $objects[] = $object;
        }, $limit);

        return $objects;
    }

    /**
     * Walk the remote tree, handing each file to $onObject, and always close the connection.
     */
    private function streamSftp(BackupDestination $destination, callable $onObject, int $limit = PHP_INT_MAX): void
    {
        $sftp = $this->sftp($destination);

        try {
            $base = (string) $destination->setting('remote_path', '/');

            if (! $this->sftpRootIsDirectory($sftp, $base)) {
                throw new RuntimeException('SFTP backup root must be a regular directory.');
            }

            $count = 0;
            $this->collectSftpFiles($sftp, $base, '', $onObject, $limit, $count);
        } finally {
            $sftp->disconnect();
        }
    }

    private function uploadSftp(BackupDestination $destination, string $sourcePath, string $filename, ?string $directory): string
    {
        $sftp = $this->sftp($destination);
        $key = $this->joinRelative($directory, $filename);
        $remotePath = $this->joinAbsolute((string) $destination->setting('remote_path', '/'), $key);
        $sftp->mkdir(dirname($remotePath), -1, true);

        if (! $sftp->put($remotePath, $sourcePath, SFTP::SOURCE_LOCAL_FILE)) {
            throw new RuntimeException('Unable to upload file over SFTP.');
        }

        return $key;
    }

    private function downloadSftp(BackupDestination $destination, string $key, string $targetPath, ?callable $progress): void
    {
        $key = $this->assertLocalKey($key);
        $sftp = $this->sftp($destination);

        try {
            $remotePath = $this->joinAbsolute((string) $destination->setting('remote_path', '/'), $key);

            if (! $this->sftpObjectIsRegularFile($sftp, $destination, $key)) {
                throw new RuntimeException('SFTP backup path must contain only regular directories and a regular file.');
            }

            $downloaded = $progress === null
                ? $sftp->get($remotePath, $targetPath)
                : $sftp->get($remotePath, $targetPath, 0, -1, $progress);

            if (! $downloaded) {
                throw new RuntimeException('Unable to download file over SFTP.');
            }
        } finally {
            $sftp->disconnect();
        }
    }

    private function hasSftpObject(BackupDestination $destination, string $key): bool
    {
        $sftp = $this->sftp($destination);

        try {
            return $this->sftpObjectIsRegularFile($sftp, $destination, $this->assertLocalKey($key));
        } finally {
            $sftp->disconnect();
        }
    }

    private function sftpObjectIsRegularFile(SFTP $sftp, BackupDestination $destination, string $key): bool
    {
        $segments = explode('/', $key);
        $remotePath = rtrim((string) $destination->setting('remote_path', '/'), '/');

        if (! $this->sftpRootIsDirectory($sftp, $remotePath)) {
            return false;
        }

        foreach ($segments as $index => $segment) {
            $remotePath = $this->joinAbsolute($remotePath, $segment);
            $attributes = $sftp->lstat($remotePath);

            if (! is_array($attributes) || ! isset($attributes['type'])) {
                return false;
            }

            $type = (int) $attributes['type'];
            $isTarget = $index === array_key_last($segments);

            if ($isTarget) {
                return $type === self::SFTP_TYPE_REGULAR;
            }

            if ($type !== self::SFTP_TYPE_DIRECTORY) {
                return false;
            }
        }

        return false;
    }

    private function sftpRootIsDirectory(SFTP $sftp, string $path): bool
    {
        $attributes = $sftp->lstat(rtrim($path, '/') ?: '/');

        return is_array($attributes)
            && isset($attributes['type'])
            && (int) $attributes['type'] === self::SFTP_TYPE_DIRECTORY;
    }

    /**
     * Connect to an SSH server and read the host key it presents, without
     * authenticating (the key exchange happens before login). Used by the UI
     * to let an admin trust a server's key on first use.
     *
     * @return array{key: string, fingerprint: string}
     */
    public function probeHostKey(string $host, int $port = 22): array
    {
        if (! SftpEndpointHost::isValid($host) || $port < 1 || $port > 65535) {
            throw new RuntimeException('Invalid SSH endpoint.');
        }
        $this->outboundHostGuard->assertHostAllowed($host);

        $key = $this->newSftp($host, $port)->getServerPublicHostKey();

        if (! is_string($key) || $key === '' || strlen($key) > 4096 || self::hostKeyFingerprint($key) === '') {
            throw new RuntimeException('Unable to read the host key from the SSH server.');
        }

        return [
            'key' => $key,
            'fingerprint' => self::hostKeyFingerprint($key),
        ];
    }

    protected function newSftp(string $host, int $port): SFTP
    {
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (! filter_var($host, FILTER_VALIDATE_IP)) {
            foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (isset($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }
        if ($addresses === []) {
            throw new RuntimeException('Unable to resolve the SSH server.');
        }
        foreach ($addresses as $address) {
            $this->outboundHostGuard->assertHostAllowed($address);
        }

        $address = filter_var($addresses[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '['.$addresses[0].']' : $addresses[0];

        return new SFTP($address, $port, 15);
    }

    protected function sftp(BackupDestination $destination): SFTP
    {
        $sftp = $this->newSftp((string) $destination->setting('host'), (int) $destination->setting('port', 22));

        // Pin the server host key BEFORE authenticating, so a man-in-the-middle
        // never receives the username/password/private key. This only guards
        // VolumeVault's own SFTP operations (test, listing, restore download):
        // the offen backup container ignores host keys entirely (upstream
        // hardcodes ssh.InsecureIgnoreHostKey), so the actual backup upload
        // cannot be pinned here.
        $this->verifyHostKey($destination, $sftp);

        $credential = (string) $destination->secret('password', '');

        if (filled($destination->secret('private_key'))) {
            $credential = PublicKeyLoader::load((string) $destination->secret('private_key'), $destination->secret('private_key_passphrase') ?: false);
        }

        if (! $sftp->login((string) $destination->secret('user'), $credential)) {
            throw new RuntimeException('Unable to authenticate to the SFTP destination.');
        }

        return $sftp;
    }

    /**
     * Reject the connection when a pinned host key is configured and the key
     * the server presents does not match it. No pin configured => no check
     * (trust-on-first-use is left to the admin, who can read the fingerprint
     * via hostKeyFingerprint()).
     */
    protected function verifyHostKey(BackupDestination $destination, SFTP $sftp): void
    {
        $pinned = trim((string) $destination->setting('host_key', ''));

        if ($pinned === '') {
            return;
        }

        $presented = $sftp->getServerPublicHostKey();

        if (! is_string($presented) || $presented === '') {
            throw new RuntimeException('Unable to read the SFTP server host key for verification.');
        }

        if (! self::hostKeyMatches($pinned, $presented)) {
            throw new RuntimeException(sprintf(
                'SFTP host key verification failed: the server key does not match the pinned key. Presented fingerprint: %s',
                self::hostKeyFingerprint($presented) ?: 'unknown',
            ));
        }
    }

    /**
     * Compare a pinned host key against the one the server presented. The pin
     * may be an OpenSSH public key line ("ssh-ed25519 AAAA...", with or
     * without a trailing comment), a bare base64 key blob, or a SHA256
     * fingerprint ("SHA256:...").
     */
    public static function hostKeyMatches(string $pinned, string $presented): bool
    {
        return SshHostKey::matches($pinned, $presented);
    }

    /**
     * SHA256 fingerprint of a host key, in OpenSSH's "SHA256:<base64>" form
     * (matching `ssh-keygen -lf`). Empty string when no key blob is found.
     */
    public static function hostKeyFingerprint(string $key): string
    {
        return SshHostKey::fingerprint($key);
    }

    private function collectSftpFiles(SFTP $sftp, string $directory, string $prefix, callable $onObject, int $limit, int &$count): void
    {
        if ($count >= $limit) {
            return;
        }

        $entries = $sftp->rawlist($directory) ?: [];

        foreach ($entries as $name => $attributes) {
            if ($name === '.' || $name === '..' || $count >= $limit) {
                continue;
            }

            $key = $this->joinRelative($prefix, $name);

            try {
                $key = $this->assertLocalKey($key);
            } catch (RuntimeException) {
                continue;
            }

            $path = $this->joinAbsolute($directory, $name);

            $type = isset($attributes['type']) ? (int) $attributes['type'] : null;

            if ($type === self::SFTP_TYPE_DIRECTORY) {
                $this->collectSftpFiles($sftp, $path, $key, $onObject, $limit, $count);

                continue;
            }

            if ($type !== self::SFTP_TYPE_REGULAR) {
                continue;
            }

            $onObject([
                'key' => $key,
                'display_name' => $key,
                'size' => (int) ($attributes['size'] ?? 0),
                'last_modified' => isset($attributes['mtime']) ? date(DATE_ATOM, (int) $attributes['mtime']) : null,
            ]);
            $count++;
        }
    }

    private function listAzure(BackupDestination $destination, int $limit = 1000): array
    {
        $objects = [];
        $marker = null;

        do {
            $query = [
                'restype' => 'container',
                'comp' => 'list',
                'maxresults' => (string) min(max($limit - count($objects), 1), 5000),
            ];

            if ($marker) {
                $query['marker'] = $marker;
            }

            $response = $this->azureContainerRequest($destination, 'GET', $query);
            $xml = simplexml_load_string($response->body());

            if ($xml === false) {
                throw new RuntimeException('Unable to parse Azure Blob response.');
            }

            foreach ($xml->Blobs->Blob ?? [] as $blob) {
                if (count($objects) >= $limit) {
                    break;
                }

                $objects[] = [
                    'key' => (string) $blob->Name,
                    'display_name' => (string) $blob->Name,
                    'size' => (int) $blob->Properties->{'Content-Length'},
                    'last_modified' => date(DATE_ATOM, strtotime((string) $blob->Properties->{'Last-Modified'})),
                ];
            }

            $marker = isset($xml->NextMarker) ? (string) $xml->NextMarker : '';
        } while ($marker !== '' && count($objects) < $limit);

        return $objects;
    }

    private function uploadAzure(BackupDestination $destination, string $sourcePath, string $filename): string
    {
        $this->azureBlobRequest($destination, 'PUT', $filename, [], [
            'x-ms-blob-type' => 'BlockBlob',
            'Content-Type' => 'application/octet-stream',
        ], File::get($sourcePath));

        return $filename;
    }

    private function downloadAzure(BackupDestination $destination, string $key, string $targetPath, ?callable $progress): void
    {
        $this->azureBlobRequest($destination, 'GET', $key, [], [], null, $targetPath, [], $progress);
    }

    private function hasAzureObject(BackupDestination $destination, string $key): bool
    {
        return $this->azureBlobRequest($destination, 'HEAD', $key, allowedStatuses: [404])->status() !== 404;
    }

    private function azureContainerRequest(BackupDestination $destination, string $method, array $query = []): Response
    {
        return $this->azureRequest($destination, $method, '', '', $query);
    }

    private function azureBlobRequest(BackupDestination $destination, string $method, string $key, array $query = [], array $headers = [], ?string $body = null, ?string $sink = null, array $allowedStatuses = [], ?callable $progress = null): Response
    {
        return $this->azureRequest(
            $destination,
            $method,
            '/'.$key,
            '/'.$this->encodeAzureBlobKey($key),
            $query,
            $headers,
            $body,
            $sink,
            $allowedStatuses,
            $progress,
        );
    }

    private function azureRequest(BackupDestination $destination, string $method, string $canonicalPath, string $encodedPath, array $query = [], array $headers = [], ?string $body = null, ?string $sink = null, array $allowedStatuses = [], ?callable $progress = null): Response
    {
        $config = $this->azureConfig($destination);
        $url = rtrim($config['endpoint'], '/').'/'.$config['container'].$encodedPath;

        if ($query) {
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        if ($config['sas']) {
            $url .= (str_contains($url, '?') ? '&' : '?').ltrim($config['sas'], '?');
        }

        $headers = array_merge([
            'x-ms-date' => gmdate('D, d M Y H:i:s').' GMT',
            'x-ms-version' => '2021-12-02',
        ], $headers);

        if ($body !== null) {
            $headers['Content-Length'] = (string) strlen($body);
        }

        if (! $config['sas']) {
            $headers['Authorization'] = $this->azureAuthorization($method, $config, $canonicalPath, $query, $headers, $body);
        }

        $options = [];
        if ($body !== null) {
            $options['body'] = $body;
        }
        if ($sink) {
            $options['sink'] = $sink;
        }
        if ($progress !== null) {
            $options['progress'] = $progress;
        }

        $response = Http::withHeaders($headers)->send($method, $url, $options);

        if ($response->failed() && ! in_array($response->status(), $allowedStatuses, true)) {
            throw new RuntimeException('Azure Blob request failed with HTTP '.$response->status().'.');
        }

        return $response;
    }

    private function azureAuthorization(string $method, array $config, string $canonicalPath, array $query, array $headers, ?string $body): string
    {
        $canonicalHeaders = collect($headers)
            ->filter(fn (mixed $value, string $key) => str_starts_with(strtolower($key), 'x-ms-'))
            ->mapWithKeys(fn (mixed $value, string $key) => [strtolower($key) => trim((string) $value)])
            ->sortKeys()
            ->map(fn (string $value, string $key) => $key.':'.$value."\n")
            ->implode('');

        $canonicalResource = '/'.$config['account'].'/'.$config['container'].$canonicalPath;
        foreach (collect($query)->mapWithKeys(fn (mixed $value, string $key) => [strtolower($key) => $value])->sortKeys() as $key => $value) {
            $canonicalResource .= "\n".$key.':'.$value;
        }

        $contentLength = $body === null || $body === '' ? '' : (string) strlen($body);
        $contentType = $headers['Content-Type'] ?? '';
        $stringToSign = implode("\n", [
            $method,
            '',
            '',
            $contentLength,
            '',
            $contentType,
            '',
            '',
            '',
            '',
            '',
            '',
        ])."\n".$canonicalHeaders.$canonicalResource;

        $signature = base64_encode(hash_hmac('sha256', $stringToSign, base64_decode($config['key'], true) ?: '', true));

        return 'SharedKey '.$config['account'].':'.$signature;
    }

    private function encodeAzureBlobKey(string $key): string
    {
        return collect(explode('/', $key))
            ->map(function (string $segment): string {
                if ($segment === '.' || $segment === '..') {
                    return str_repeat('%2E', strlen($segment));
                }

                return rawurlencode($segment);
            })
            ->implode('/');
    }

    private function azureConfig(BackupDestination $destination): array
    {
        $connection = $this->parseConnectionString((string) $destination->secret('connection_string', ''));
        $account = (string) ($destination->setting('account_name') ?: ($connection['AccountName'] ?? ''));
        $endpoint = $destination->setting('endpoint') ?: ($connection['BlobEndpoint'] ?? null);

        if (! $endpoint && $account !== '') {
            $protocol = $connection['DefaultEndpointsProtocol'] ?? 'https';
            $suffix = $connection['EndpointSuffix'] ?? 'blob.core.windows.net';
            $endpoint = $protocol.'://'.$account.'.'.$suffix;
        }

        $key = (string) ($destination->secret('account_key') ?: ($connection['AccountKey'] ?? ''));
        $sas = $connection['SharedAccessSignature'] ?? null;

        if ($account === '' || ! $endpoint || (! $key && ! $sas)) {
            throw new RuntimeException('Azure Blob destination requires an account/key or a SAS connection string.');
        }

        return [
            'account' => $account,
            'key' => $key,
            'sas' => $sas,
            'endpoint' => $endpoint,
            'container' => (string) $destination->setting('container'),
        ];
    }

    private function parseConnectionString(string $connectionString): array
    {
        return collect(explode(';', $connectionString))
            ->filter(fn (string $part) => str_contains($part, '='))
            ->mapWithKeys(function (string $part) {
                [$key, $value] = explode('=', $part, 2);

                return [$key => $value];
            })
            ->all();
    }

    private function listDropbox(BackupDestination $destination, int $limit = 1000): array
    {
        $token = $this->dropboxToken($destination);
        $path = $this->dropboxPath($destination);
        $objects = [];
        $seenIds = [];
        $response = Http::withToken($token)->post('https://api.dropboxapi.com/2/files/list_folder', [
            'path' => $path,
            'recursive' => true,
            'include_deleted' => false,
        ]);

        $this->ensureDropboxOk($response);
        $payload = $response->json();

        while (true) {
            foreach ($payload['entries'] ?? [] as $entry) {
                if (count($objects) >= $limit) {
                    break 2;
                }

                if (($entry['.tag'] ?? null) !== 'file') {
                    continue;
                }

                $id = $entry['id'] ?? null;

                if (! is_string($id) || ! str_starts_with($id, 'id:')) {
                    continue;
                }

                if (isset($seenIds[$id])) {
                    continue;
                }

                $displayName = $this->dropboxDisplayName($destination, $entry);

                if ($displayName === null) {
                    continue;
                }

                $seenIds[$id] = true;

                $objects[] = [
                    'key' => $id,
                    'display_name' => $displayName,
                    'size' => (int) ($entry['size'] ?? 0),
                    'last_modified' => isset($entry['server_modified']) ? date(DATE_ATOM, strtotime($entry['server_modified'])) : null,
                ];
            }

            if (! ($payload['has_more'] ?? false)) {
                break;
            }

            $response = Http::withToken($token)->post('https://api.dropboxapi.com/2/files/list_folder/continue', [
                'cursor' => $payload['cursor'],
            ]);
            $this->ensureDropboxOk($response);
            $payload = $response->json();
        }

        return $objects;
    }

    private function uploadDropbox(BackupDestination $destination, string $sourcePath, string $filename, ?string $directory): string
    {
        $token = $this->dropboxToken($destination);
        $path = $this->dropboxPath($destination, $directory, $filename);
        $response = Http::withToken($token)
            ->withHeaders([
                'Dropbox-API-Arg' => json_encode(['path' => $path, 'mode' => 'add', 'autorename' => true, 'mute' => false]),
                'Content-Type' => 'application/octet-stream',
            ])
            ->send('POST', 'https://content.dropboxapi.com/2/files/upload', ['body' => File::get($sourcePath)]);

        $this->ensureDropboxOk($response);

        $id = $response->json('id');

        if (! is_string($id) || ! str_starts_with($id, 'id:')) {
            throw new RuntimeException('Dropbox upload response did not include a file ID.');
        }

        return $id;
    }

    private function downloadDropbox(BackupDestination $destination, string $key, string $targetPath, ?callable $progress): void
    {
        $options = ['sink' => $targetPath];

        if ($progress !== null) {
            $options['progress'] = $progress;
        }

        $response = Http::withToken($this->dropboxToken($destination))
            ->withHeaders(['Dropbox-API-Arg' => json_encode(['path' => $key])])
            ->send('POST', 'https://content.dropboxapi.com/2/files/download', $options);

        $this->ensureDropboxOk($response);
    }

    private function hasDropboxObject(BackupDestination $destination, string $key): bool
    {
        return $this->dropboxObjectMetadata($destination, $key) !== null;
    }

    /** @return array<string, mixed>|null */
    private function dropboxObjectMetadata(BackupDestination $destination, string $key): ?array
    {
        $response = Http::withToken($this->dropboxToken($destination))
            ->post('https://api.dropboxapi.com/2/files/get_metadata', [
                'path' => $key,
                'include_deleted' => false,
            ]);

        if ($response->status() === 409) {
            return null;
        }

        $this->ensureDropboxOk($response);
        $metadata = (array) $response->json();

        if (($metadata['.tag'] ?? null) !== 'file') {
            return null;
        }

        $id = $metadata['id'] ?? null;

        if (! is_string($id) || ! str_starts_with($id, 'id:')) {
            return null;
        }

        $displayName = $this->dropboxDisplayName($destination, $metadata);

        if (str_starts_with($key, 'id:')) {
            if ($id !== $key) {
                return null;
            }

            $displayName ??= (string) ($metadata['name'] ?? basename((string) ($metadata['path_display'] ?? $key)));
        }

        if ($displayName === null) {
            return null;
        }

        return [
            'key' => $id,
            'display_name' => $displayName,
            'size' => (int) ($metadata['size'] ?? 0),
            'last_modified' => filled($modified = $metadata['server_modified'] ?? null) ? date(DATE_ATOM, strtotime((string) $modified)) : null,
        ];
    }

    private function dropboxToken(BackupDestination $destination): string
    {
        $response = Http::asForm()->post('https://api.dropboxapi.com/oauth2/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $destination->secret('refresh_token'),
            'client_id' => $destination->secret('app_key'),
            'client_secret' => $destination->secret('app_secret'),
        ]);

        $this->ensureDropboxOk($response);

        return (string) $response->json('access_token');
    }

    private function ensureDropboxOk(Response $response): void
    {
        if ($response->failed()) {
            throw new RuntimeException('Dropbox request failed with HTTP '.$response->status().'.');
        }
    }

    private function dropboxPath(BackupDestination $destination, ?string $directory = null, ?string $filename = null): string
    {
        $path = $this->joinRelative($destination->setting('remote_path'), $directory, $filename);

        return $path === '' ? '' : '/'.ltrim($path, '/');
    }

    /** @param array<string, mixed> $entry */
    private function dropboxDisplayName(BackupDestination $destination, array $entry): ?string
    {
        $displayPath = (string) ($entry['path_display'] ?? '');
        $lowerPath = (string) ($entry['path_lower'] ?? mb_strtolower($displayPath));
        $root = $this->dropboxPath($destination);
        $lowerRoot = mb_strtolower($root);

        if ($displayPath === '') {
            return null;
        }

        if ($lowerRoot !== '' && ! str_starts_with($lowerPath, $lowerRoot.'/')) {
            return null;
        }

        $rootSegmentCount = count(array_filter(explode('/', trim($root, '/')), fn (string $segment): bool => $segment !== ''));
        $displaySegments = array_values(array_filter(explode('/', trim($displayPath, '/')), fn (string $segment): bool => $segment !== ''));
        $relativeSegments = array_slice($displaySegments, $rootSegmentCount);

        return $relativeSegments === [] ? null : implode('/', $relativeSegments);
    }

    private function listGoogleDrive(BackupDestination $destination, int $limit = 1000): array
    {
        $token = $this->googleDriveToken($destination);
        $objects = [];
        $pageToken = null;

        do {
            $query = [
                'q' => "'".$destination->setting('folder_id')."' in parents and trashed = false",
                'fields' => 'nextPageToken,files(id,name,size,modifiedTime,mimeType)',
                'orderBy' => 'modifiedTime desc',
                'pageSize' => min(max($limit - count($objects), 1), 1000),
                'supportsAllDrives' => 'true',
                'includeItemsFromAllDrives' => 'true',
            ];

            if ($pageToken) {
                $query['pageToken'] = $pageToken;
            }

            $response = Http::withToken($token)->get($this->googleDriveEndpoint($destination).'/files', $query);

            if ($response->failed()) {
                throw new RuntimeException('Google Drive request failed with HTTP '.$response->status().'.');
            }

            foreach ($response->json('files') ?? [] as $file) {
                if (count($objects) >= $limit) {
                    break;
                }

                if (($file['mimeType'] ?? null) === 'application/vnd.google-apps.folder') {
                    continue;
                }

                $objects[] = [
                    'key' => 'gdrive:'.$file['id'],
                    'display_name' => (string) $file['name'],
                    'size' => (int) ($file['size'] ?? 0),
                    'last_modified' => isset($file['modifiedTime']) ? date(DATE_ATOM, strtotime($file['modifiedTime'])) : null,
                ];
            }

            $pageToken = $response->json('nextPageToken');
        } while ($pageToken && count($objects) < $limit);

        return $objects;
    }

    private function uploadGoogleDrive(BackupDestination $destination, string $sourcePath, string $filename): string
    {
        $boundary = 'volumevault-'.Str::random(24);
        $body = '--'.$boundary."\r\n".
            "Content-Type: application/json; charset=UTF-8\r\n\r\n".
            json_encode(['name' => $filename, 'parents' => [$destination->setting('folder_id')]], JSON_THROW_ON_ERROR)."\r\n".
            '--'.$boundary."\r\n".
            "Content-Type: application/octet-stream\r\n\r\n".
            File::get($sourcePath)."\r\n".
            '--'.$boundary.'--';

        $response = Http::withToken($this->googleDriveToken($destination))
            ->withHeaders(['Content-Type' => 'multipart/related; boundary='.$boundary])
            ->send('POST', $this->googleDriveUploadEndpoint($destination).'/files?uploadType=multipart&supportsAllDrives=true', ['body' => $body]);

        if ($response->failed()) {
            throw new RuntimeException('Google Drive upload failed with HTTP '.$response->status().'.');
        }

        return 'gdrive:'.$response->json('id');
    }

    private function downloadGoogleDrive(BackupDestination $destination, string $key, string $targetPath, ?callable $progress): void
    {
        $id = Str::startsWith($key, 'gdrive:') ? Str::after($key, 'gdrive:') : $key;
        $options = ['sink' => $targetPath];

        if ($progress !== null) {
            $options['progress'] = $progress;
        }

        $response = Http::withToken($this->googleDriveToken($destination))
            ->send('GET', $this->googleDriveEndpoint($destination).'/files/'.$id.'?alt=media&supportsAllDrives=true', $options);

        if ($response->failed()) {
            throw new RuntimeException('Google Drive download failed with HTTP '.$response->status().'.');
        }
    }

    private function hasGoogleDriveObject(BackupDestination $destination, string $key): bool
    {
        if (! Str::startsWith($key, 'gdrive:') || blank($id = Str::after($key, 'gdrive:'))) {
            return false;
        }

        $response = Http::withToken($this->googleDriveToken($destination))->get(
            $this->googleDriveEndpoint($destination).'/files/'.rawurlencode($id),
            ['fields' => 'id,parents,trashed,mimeType', 'supportsAllDrives' => 'true'],
        );

        if ($response->status() === 404) {
            return false;
        }

        if ($response->failed()) {
            throw new RuntimeException('Google Drive request failed with HTTP '.$response->status().'.');
        }

        return ! $response->json('trashed', false)
            && $response->json('mimeType') !== 'application/vnd.google-apps.folder'
            && in_array((string) $destination->setting('folder_id'), $response->json('parents', []), true);
    }

    /** @return array{key: string, display_name: string}|null */
    private function findGoogleDriveObjectByFilename(BackupDestination $destination, string $filename): ?array
    {
        $escapedFilename = str_replace(['\\', "'"], ['\\\\', "\\'"], $filename);
        $response = Http::withToken($this->googleDriveToken($destination))->get(
            $this->googleDriveEndpoint($destination).'/files',
            [
                'q' => "'".$destination->setting('folder_id')."' in parents and name = '{$escapedFilename}' and trashed = false",
                'fields' => 'files(id,name,mimeType)',
                'pageSize' => 2,
                'supportsAllDrives' => 'true',
                'includeItemsFromAllDrives' => 'true',
            ],
        );

        if ($response->failed()) {
            throw new RuntimeException('Google Drive request failed with HTTP '.$response->status().'.');
        }

        $files = collect($response->json('files', []))
            ->filter(fn (array $file): bool => ($file['name'] ?? null) === $filename
                && ($file['mimeType'] ?? null) !== 'application/vnd.google-apps.folder')
            ->values();

        if ($files->count() !== 1) {
            return null;
        }

        return ['key' => 'gdrive:'.$files[0]['id'], 'display_name' => $filename];
    }

    private function googleDriveToken(BackupDestination $destination): string
    {
        $credentials = json_decode((string) $destination->secret('credentials_json'), true, flags: JSON_THROW_ON_ERROR);
        $tokenUrl = $this->googleDriveTokenUrl($destination, $credentials);
        $now = time();
        $claims = [
            'iss' => $credentials['client_email'] ?? null,
            'scope' => 'https://www.googleapis.com/auth/drive',
            'aud' => $tokenUrl,
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        if (filled($destination->setting('impersonate_subject'))) {
            $claims['sub'] = $destination->setting('impersonate_subject');
        }

        $unsigned = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)).'.'.$this->base64Url(json_encode($claims, JSON_THROW_ON_ERROR));

        if (! openssl_sign($unsigned, $signature, (string) ($credentials['private_key'] ?? ''), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign Google Drive service account assertion.');
        }

        $response = Http::asForm()->post($tokenUrl, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $unsigned.'.'.$this->base64Url($signature),
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Google Drive authentication failed with HTTP '.$response->status().'.');
        }

        return (string) $response->json('access_token');
    }

    private function googleDriveEndpoint(BackupDestination $destination): string
    {
        $endpoint = rtrim((string) ($destination->setting('endpoint') ?: 'https://www.googleapis.com/drive/v3'), '/');
        $this->outboundHostGuard->assertUrlAllowed($endpoint);

        return $endpoint;
    }

    private function googleDriveUploadEndpoint(BackupDestination $destination): string
    {
        $endpoint = $this->googleDriveEndpoint($destination);
        $parts = parse_url($endpoint);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException('Google Drive API endpoint must be a valid URL.');
        }

        $authority = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $uploadEndpoint = $authority.'/upload'.($parts['path'] ?? '');
        $this->outboundHostGuard->assertUrlAllowed($uploadEndpoint);

        return rtrim($uploadEndpoint, '/');
    }

    /** @param array<string, mixed> $credentials */
    private function googleDriveTokenUrl(BackupDestination $destination, array $credentials): string
    {
        $tokenUrl = (string) ($destination->setting('token_url') ?: ($credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token'));
        $this->outboundHostGuard->assertUrlAllowed($tokenUrl);

        return $tokenUrl;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function testLocal(BackupDestination $destination): void
    {
        $path = (string) $destination->setting('archive_path');
        $this->hostPathPolicy->assertValidAtRuntime($path);

        if (! File::isDirectory($path)) {
            throw new RuntimeException('Local archive path does not exist or is not a directory.');
        }

        if (! is_readable($path) || ! is_writable($path)) {
            throw new RuntimeException('Local archive path must be readable and writable by VolumeVault.');
        }
    }

    private function listLocal(BackupDestination $destination, int $limit = 1000): array
    {
        $this->testLocal($destination);
        $base = rtrim((string) $destination->setting('archive_path'), '/');

        // Stream the directory tree and stop at the cap instead of materializing
        // every file (File::allFiles) into a single JSON response, matching the
        // 1000-item cap the other providers already enforce.
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        $objects = [];

        foreach ($iterator as $file) {
            if ($file->isLink() || ! $file->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));

            try {
                $relative = $this->assertLocalKey($relative);
            } catch (RuntimeException) {
                continue;
            }

            if (count($objects) >= $limit) {
                break;
            }

            $objects[] = [
                'key' => $relative,
                'display_name' => $relative,
                'size' => $file->getSize(),
                'last_modified' => date(DATE_ATOM, $file->getMTime()),
            ];
        }

        return $objects;
    }

    private function uploadLocal(BackupDestination $destination, string $sourcePath, string $filename, ?string $directory): string
    {
        $archivePath = (string) $destination->setting('archive_path');
        $this->hostPathPolicy->assertValidAtRuntime($archivePath);
        $key = $this->assertLocalKey($this->joinRelative($directory, $filename));
        $archiveRoot = realpath($archivePath);

        if ($archiveRoot === false) {
            throw new RuntimeException('Local archive path does not exist.');
        }

        $rootStat = @lstat($archiveRoot);

        if ($rootStat === false || ($rootStat['mode'] & 0170000) !== 0040000) {
            throw new RuntimeException('Local archive path changed while it was being opened.');
        }

        $this->secureLocalArchiveReader->write($archiveRoot, $key, $sourcePath, $rootStat);

        return $key;
    }

    private function downloadLocal(BackupDestination $destination, string $key, string $targetPath, ?callable $progress): void
    {
        $key = $this->assertLocalKey($key);
        $configuredRoot = (string) $destination->setting('archive_path');
        $this->hostPathPolicy->assertValidAtRuntime($configuredRoot);
        $archiveRoot = realpath($configuredRoot);

        if ($archiveRoot === false) {
            throw new RuntimeException('Local archive path does not exist.');
        }

        $this->hostPathPolicy->assertValidAtRuntime($archiveRoot);

        $rootStat = @lstat($archiveRoot);

        if ($rootStat === false || ($rootStat['mode'] & 0170000) !== 0040000) {
            throw new RuntimeException('Local archive path changed while it was being opened.');
        }

        $this->secureLocalArchiveReader->copy($archiveRoot, $key, $targetPath, $rootStat, $progress, $this->operationMaxBytes);
    }

    private function hasLocalObject(BackupDestination $destination, string $key): bool
    {
        $this->testLocal($destination);
        $archiveRoot = realpath((string) $destination->setting('archive_path'));

        if ($archiveRoot === false) {
            return false;
        }

        $segments = explode('/', $this->assertLocalKey($key));
        $path = $archiveRoot;

        foreach ($segments as $index => $segment) {
            $path .= DIRECTORY_SEPARATOR.$segment;
            $stat = @lstat($path);

            if ($stat === false) {
                return false;
            }

            $type = $stat['mode'] & 0170000;

            if ($index === array_key_last($segments)) {
                return $type === 0100000;
            }

            if ($type !== 0040000) {
                return false;
            }
        }

        return false;
    }

    private function assertLocalKey(string $key): string
    {
        if (
            $key === ''
            || strlen($key) > 1024
            || str_starts_with($key, '/')
            || str_starts_with($key, '\\')
            || str_contains($key, '\\')
            || preg_match('/^[A-Za-z]:/', $key) === 1
            || preg_match('/[\x00-\x1F\x7F]/', $key) === 1
        ) {
            throw new RuntimeException('Local backup key must stay within the archive path.');
        }

        foreach (explode('/', $key) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || strlen($segment) > 255) {
                throw new RuntimeException('Local backup key must stay within the archive path.');
            }
        }

        return $key;
    }

    /**
     * The validated [volume name, archive directory] for a Docker volume
     * destination. The name is re-checked here (fail-closed / TOCTOU) before it
     * ever reaches a `-v` spec, so a `/` cannot turn the source into a host bind
     * mount and a `:` cannot inject extra mount options.
     *
     * @return array{0: string, 1: string}
     */
    private function dockerVolumeTarget(BackupDestination $destination): array
    {
        $volume = DockerVolumeName::assertName((string) $destination->setting('volume_name'));

        // `docker run -v <name>:...` silently re-creates a missing named volume
        // as an empty `local` volume, which would make testing/listing report a
        // healthy-but-empty destination and let backups write to the wrong place.
        // Fail loudly instead so a deleted volume surfaces as a clear error.
        $this->assertVolumeExists($volume);

        return [
            $volume,
            DockerVolumeName::archiveDir((string) $destination->setting('path_prefix')),
        ];
    }

    private function assertVolumeExists(string $volume): void
    {
        $result = $this->dockerProcess->run(['docker', 'volume', 'inspect', $volume], 60);

        if (! $result->successful()) {
            throw new RuntimeException('The Docker volume "'.$volume.'" does not exist. Create it (for example in your Compose file) before using this destination.');
        }
    }

    /**
     * Prove the volume mounts and its (optional) sub-directory is writable from
     * a throwaway container — the same way the Offen backup container will write
     * there. A bare `docker volume inspect` would miss a read-only NFS export,
     * so we actually create and remove a probe file. This also provisions the
     * sub-directory so the first scheduled backup does not fail on a missing dir.
     */
    private function testDockerVolume(BackupDestination $destination): void
    {
        [$volume, $dir] = $this->dockerVolumeTarget($destination);

        // Probe with a random, per-test filename and `set -C` (noclobber) so the
        // write test can never truncate or delete a pre-existing file in the
        // destination. The dir ($1) and probe name ($2) are positional arguments,
        // never interpolated into the script, so they cannot break out of it.
        $probe = Str::lower(Str::random(16));
        $script = 'set -e; mkdir -p "$1"; set -C; : > "$1/.vv-write-test-$2"; set +C; rm -f "$1/.vv-write-test-$2"';
        $command = [
            'docker', 'run', '--rm',
            '-v', $volume.':'.DockerVolumeName::MOUNT_POINT,
            '--entrypoint', 'sh',
            RunBackupContainer::IMAGE,
            '-c', $script, 'sh', $dir, $probe,
        ];

        $result = $this->dockerProcess->run($command, 120);

        if (! $result->successful()) {
            throw new RuntimeException('Docker volume destination is not usable: '.($result->combinedOutput() ?: 'unknown error'));
        }
    }

    /**
     * List backup objects from a read-only mount of the volume. Only an absent
     * sub-directory is treated as an empty destination.
     */
    private function listDockerVolume(BackupDestination $destination, int $limit = 1000): array
    {
        [$volume, $dir] = $this->dockerVolumeTarget($destination);

        // Reject unsafe keys and sort newest first before the in-container cap. PHP
        // repeats the validation below as the run-time trust boundary.
        $filter = 'awk -v prefix="$1/" \'{ first = index($0, "|"); if (! first) next; rest = substr($0, first + 1); second = index(rest, "|"); if (! second) next; path = substr(rest, second + 1); if (substr(path, 1, length(prefix)) != prefix) next; key = substr(path, length(prefix) + 1); lower = tolower(key); if (! length(key) || length(key) > 1024 || substr(key, 1, 1) == "\\\\" || key ~ /^[A-Za-z]:/ || key ~ /[[:cntrl:]]/ || lower !~ /\.(tar|tar\.gz|tgz|tar\.zst|gz|zst)(\.(gpg|age))?$/) next; count_segments = split(key, segments, "/"); valid = 1; for (i = 1; i <= count_segments; i++) if (! length(segments[i]) || segments[i] == "." || segments[i] == ".." || length(segments[i]) > 255) valid = 0; if (! valid) next; print }\'';
        // Spool each stage to disk: portable sh has no pipefail, and head can
        // otherwise cause a successful upstream stage to fail with SIGPIPE.
        $script = <<<'SH'
parent="$1"
while [ "$parent" != / ]; do
    parent=${parent%/*}
    [ -n "$parent" ] || parent=/
    if [ -e "$parent" ] || [ -L "$parent" ]; then
        [ -d "$parent" ] && [ -x "$parent" ] || exit 1
    fi
done
if [ ! -e "$1" ] && [ ! -L "$1" ]; then
    exit 0
fi
[ -d "$1" ] || exit 1
spool=$(mktemp -d) || exit 1
trap 'rm -rf "$spool"' EXIT
trap 'exit 1' HUP INT TERM
SH;
        $script .= PHP_EOL.'find "$1" -type f ! -path "*'.PHP_EOL.'*" -print0 > "$spool/paths" || exit $?'.PHP_EOL;
        $script .= 'xargs -0 -r stat -c "%s|%Y|%n" < "$spool/paths" > "$spool/stats" || exit $?'.PHP_EOL;
        $script .= $limit === PHP_INT_MAX
            ? 'cat "$spool/stats"'
            : $filter.' < "$spool/stats" > "$spool/filtered" || exit $?'.PHP_EOL
                .'sort -t "|" -k2,2nr < "$spool/filtered" > "$spool/sorted" || exit $?'.PHP_EOL
                .'head -n "$2" < "$spool/sorted"';
        $command = [
            'docker', 'run', '--rm',
            '-v', $volume.':'.DockerVolumeName::MOUNT_POINT.':ro',
            '--entrypoint', 'sh',
            RunBackupContainer::IMAGE,
            '-c', $script, 'sh', $dir, (string) $limit,
        ];

        if ($this->operationHelperName !== null) {
            array_splice($command, 3, 0, ['--name', $this->operationHelperName]);
        }

        $result = $this->dockerProcess->run($command, 120);

        if (! $result->successful()) {
            throw new RuntimeException('Unable to list the Docker volume destination: '.($result->combinedOutput() ?: 'unknown error'));
        }

        $objects = [];

        foreach (preg_split('/\R/', trim($result->output)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            $parts = explode('|', $line, 3);

            if (count($parts) < 3) {
                continue;
            }

            [$size, $mtime, $path] = $parts;
            $prefix = rtrim($dir, '/').'/';

            if (! str_starts_with($path, $prefix)) {
                continue;
            }

            try {
                $relative = DockerVolumeName::assertKey(substr($path, strlen($prefix)));
            } catch (RuntimeException) {
                continue;
            }

            if (count($objects) >= $limit) {
                break;
            }

            $objects[] = [
                'key' => $relative,
                'display_name' => $relative,
                'size' => (int) $size,
                'last_modified' => date(DATE_ATOM, (int) $mtime),
            ];
        }

        return $objects;
    }

    /**
     * Sum size and count inside a read-only helper container with `awk`, so the
     * output is a single "bytes|count" line no matter how many files the volume
     * holds. Unlike listing every object back to PHP (and summing here), this
     * never buffers a huge listing in memory — the path the storage-limit alert
     * check takes for a docker_volume destination.
     *
     * @return array{used_bytes: int, object_count: int}
     */
    private function dockerVolumeUsage(BackupDestination $destination): array
    {
        [$volume, $dir] = $this->dockerVolumeTarget($destination);

        // `$1` is the archive dir (positional, never interpolated). A missing
        // sub-directory yields no input, so awk prints "0|0" (empty destination).
        $script = 'find "$1" -type f -exec stat -c "%s" {} + 2>/dev/null | awk \'BEGIN { bytes = 0; count = 0 } { bytes += $1; count++ } END { print bytes "|" count }\'';
        $command = [
            'docker', 'run', '--rm',
            '-v', $volume.':'.DockerVolumeName::MOUNT_POINT.':ro',
            '--entrypoint', 'sh',
            RunBackupContainer::IMAGE,
            '-c', $script, 'sh', $dir,
        ];

        if ($this->operationHelperName !== null) {
            array_splice($command, 3, 0, ['--name', $this->operationHelperName]);
        }

        $result = $this->dockerProcess->run($command, 300);

        if (! $result->successful()) {
            throw new RuntimeException('Unable to compute the Docker volume destination usage: '.($result->combinedOutput() ?: 'unknown error'));
        }

        $parts = explode('|', trim($result->output), 2);

        return [
            'used_bytes' => (int) ($parts[0] ?? 0),
            'object_count' => (int) ($parts[1] ?? 0),
        ];
    }

    /**
     * Stream a local file into the volume via stdin (`cat > target`) from a
     * throwaway container, creating the parent directory first. The target path
     * is passed as a positional argument, not interpolated into the script, and
     * the key is sanitised against traversal by {@see DockerVolumeName::assertKey()}.
     */
    private function uploadDockerVolume(BackupDestination $destination, string $sourcePath, string $filename, ?string $directory): string
    {
        [$volume, $dir] = $this->dockerVolumeTarget($destination);
        $key = DockerVolumeName::assertKey($this->joinRelative($directory, $filename));
        $path = $dir.'/'.$key;

        $script = 'set -e; mkdir -p "$(dirname "$1")"; cat > "$1"';
        $command = [
            'docker', 'run', '--rm', '-i',
            '-v', $volume.':'.DockerVolumeName::MOUNT_POINT,
            '--entrypoint', 'sh',
            RunBackupContainer::IMAGE,
            '-c', $script, 'sh', $path,
        ];

        $result = $this->dockerProcess->runWithInputFile($command, $sourcePath, 0);

        if (! $result->successful()) {
            throw new RuntimeException('Unable to upload to the Docker volume destination: '.($result->combinedOutput() ?: 'unknown error'));
        }

        return $key;
    }

    /**
     * `cat` the selected object straight into the target file (streamed, never
     * buffered in memory — archives can be many gigabytes) from a read-only
     * mount. No shell: the path is a single argv element and the key has already
     * been rejected if it contains a traversal segment or a colon.
     */
    private function downloadDockerVolume(BackupDestination $destination, string $key, string $targetPath, ?callable $progress): void
    {
        [$volume, $dir] = $this->dockerVolumeTarget($destination);
        $path = $dir.'/'.DockerVolumeName::assertKey($key);

        $command = [
            'docker', 'run', '--rm',
            '-v', $volume.':'.DockerVolumeName::MOUNT_POINT.':ro',
            '--entrypoint', 'cat',
            RunBackupContainer::IMAGE,
            $path,
        ];

        $result = $progress === null
            ? $this->dockerProcess->runWithOutputFile($command, $targetPath, 0)
            : $this->dockerProcess->whileMonitoring(
                $progress,
                fn (): DockerProcessResult => $this->dockerProcess->runWithOutputFile($command, $targetPath, 0),
            );

        if (! $result->successful()) {
            if (File::exists($targetPath)) {
                File::delete($targetPath);
            }

            throw new RuntimeException('Unable to download from the Docker volume destination: '.($result->errorOutput ?: 'unknown error'));
        }
    }

    private function hasDockerVolumeObject(BackupDestination $destination, string $key): bool
    {
        [$volume, $dir] = $this->dockerVolumeTarget($destination);
        $path = $dir.'/'.DockerVolumeName::assertKey($key);
        $command = [
            'docker', 'run', '--rm',
            '-v', $volume.':'.DockerVolumeName::MOUNT_POINT.':ro',
            '--entrypoint', 'test',
            RunBackupContainer::IMAGE,
            '-f', $path,
        ];
        if ($this->operationHelperName !== null) {
            array_splice($command, 3, 0, ['--name', $this->operationHelperName]);
        }
        $result = $this->dockerProcess->run($command, 120);

        if ($result->exitCode === 1 && ! $result->timedOut) {
            return false;
        }

        if (! $result->successful()) {
            throw new RuntimeException('Unable to inspect the Docker volume destination: '.($result->combinedOutput() ?: 'unknown error'));
        }

        return true;
    }

    private function joinRelative(mixed ...$parts): string
    {
        return collect($parts)
            ->filter(fn (mixed $part) => filled($part))
            ->map(fn (mixed $part) => trim((string) $part, '/'))
            ->filter()
            ->implode('/');
    }

    private function joinAbsolute(string $base, string $path): string
    {
        return rtrim($base, '/').'/'.ltrim($path, '/');
    }

    private function plausibleBackupKey(string $key): bool
    {
        return filled($key) && preg_match('/\.(tar|tar\.gz|tgz|tar\.zst|gz|zst)(\.(gpg|age))?$/i', $key) === 1;
    }

    /**
     * Refuse a destination whose remote host resolves to an internal address
     * before VolumeVault opens any connection to it (SSRF / metadata endpoint).
     */
    private function guardOutbound(BackupDestination $destination): void
    {
        LocalDockerExecution::assertDestination($destination);
        foreach ($this->outboundHosts($destination) as $host) {
            $this->outboundHostGuard->assertHostAllowed($host);
        }
    }

    /**
     * The remote host(s) VolumeVault itself connects to for a destination.
     * Providers backed by a fixed public API (Dropbox, Google Drive) or with no
     * network host (local) contribute nothing; standard AWS S3 / R2 only carry
     * a host when a custom endpoint is set.
     *
     * @return array<int, string>
     */
    private function outboundHosts(BackupDestination $destination): array
    {
        $hosts = match ($destination->provider) {
            BackupDestination::PROVIDER_AWS_S3,
            BackupDestination::PROVIDER_CLOUDFLARE_R2,
            BackupDestination::PROVIDER_CUSTOM_S3 => [parse_url((string) $destination->setting('endpoint'), PHP_URL_HOST)],
            BackupDestination::PROVIDER_WEBDAV => [parse_url((string) $destination->setting('url'), PHP_URL_HOST)],
            BackupDestination::PROVIDER_SSH => [$destination->setting('host')],
            BackupDestination::PROVIDER_AZURE_BLOB => [parse_url((string) $this->azureConfig($destination)['endpoint'], PHP_URL_HOST)],
            default => [],
        };

        return collect($hosts)
            ->map(fn (mixed $host): string => trim((string) $host))
            ->filter()
            ->values()
            ->all();
    }
}
