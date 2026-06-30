<?php

namespace App\Services\BackupDestinations;

use App\Actions\Docker\RunBackupContainer;
use App\Models\BackupDestination;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerVolumeName;
use App\Services\S3\S3ClientFactory;
use App\Services\Security\OutboundHostGuard;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use RuntimeException;

class DestinationStorage
{
    /** phpseclib's NET_SFTP_TYPE_DIRECTORY — only defined once an SFTP instance is constructed, so we mirror it here. */
    private const SFTP_TYPE_DIRECTORY = 2;

    private readonly OutboundHostGuard $outboundHostGuard;

    private readonly DockerProcess $dockerProcess;

    public function __construct(
        private readonly S3ClientFactory $s3ClientFactory,
        ?OutboundHostGuard $outboundHostGuard = null,
        ?DockerProcess $dockerProcess = null,
    ) {
        $this->outboundHostGuard = $outboundHostGuard ?? new OutboundHostGuard;
        $this->dockerProcess = $dockerProcess ?? new DockerProcess;
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

    /** @return array{used_bytes: int, object_count: int} */
    public function storageUsage(BackupDestination $destination): array
    {
        $cacheKey = 'destination_storage_usage_bytes_'.$destination->id;

        return Cache::remember($cacheKey, now()->addMinutes(30), fn (): array => $this->aggregateUsage($destination));
    }

    /**
     * Sum sizes and count objects without holding the full listing in memory.
     *
     * @return array{used_bytes: int, object_count: int}
     */
    private function aggregateUsage(BackupDestination $destination): array
    {
        $this->guardOutbound($destination);

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

    public function download(BackupDestination $destination, string $key, string $targetPath): void
    {
        $this->guardOutbound($destination);

        match ($destination->provider) {
            BackupDestination::PROVIDER_AWS_S3,
            BackupDestination::PROVIDER_CLOUDFLARE_R2,
            BackupDestination::PROVIDER_CUSTOM_S3 => $this->downloadS3($destination, $key, $targetPath),
            BackupDestination::PROVIDER_WEBDAV => $this->downloadWebDav($destination, $key, $targetPath),
            BackupDestination::PROVIDER_SSH => $this->downloadSftp($destination, $key, $targetPath),
            BackupDestination::PROVIDER_AZURE_BLOB => $this->downloadAzure($destination, $key, $targetPath),
            BackupDestination::PROVIDER_DROPBOX => $this->downloadDropbox($destination, $key, $targetPath),
            BackupDestination::PROVIDER_GOOGLE_DRIVE => $this->downloadGoogleDrive($destination, $key, $targetPath),
            BackupDestination::PROVIDER_LOCAL => $this->downloadLocal($destination, $key, $targetPath),
            BackupDestination::PROVIDER_DOCKER_VOLUME => $this->downloadDockerVolume($destination, $key, $targetPath),
            default => throw new RuntimeException('Unsupported backup destination provider.'),
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

    private function downloadS3(BackupDestination $destination, string $key, string $targetPath): void
    {
        $this->s3ClientFactory->make($destination)->getObject([
            'Bucket' => $destination->setting('bucket'),
            'Key' => $key,
            'SaveAs' => $targetPath,
        ]);
    }

    private function listWebDav(BackupDestination $destination, int $limit = 1000): array
    {
        $basePath = trim((string) $destination->setting('path'), '/');
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
        $baseUrlPath = rtrim(urldecode((string) parse_url($baseUrl, PHP_URL_PATH)), '/');

        foreach ($xml->children('DAV:')->response as $entry) {
            if (count($objects) >= $limit) {
                break;
            }

            $dav = $entry->children('DAV:');
            $hrefPath = urldecode((string) parse_url((string) $dav->href, PHP_URL_PATH));
            $relative = ltrim(Str::after($hrefPath, $baseUrlPath), '/');

            if ($relative === '') {
                continue;
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
        $key = $this->joinRelative($directory, $filename);
        $remotePath = $this->joinRelative($destination->setting('path'), $key);
        $this->ensureWebDavDirectory($destination, dirname($remotePath));
        $this->webDavRequest($destination, 'PUT', $this->webDavUrl($destination, $remotePath), [
            'headers' => ['Content-Type' => 'application/octet-stream'],
            'body' => File::get($sourcePath),
        ]);

        return $key;
    }

    private function downloadWebDav(BackupDestination $destination, string $key, string $targetPath): void
    {
        $remotePath = $this->joinRelative($destination->setting('path'), $key);
        $this->webDavRequest($destination, 'GET', $this->webDavUrl($destination, $remotePath), ['sink' => $targetPath]);
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
        $path = trim($path, '/.');

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
        return rtrim((string) $destination->setting('url'), '/').'/'.ltrim($path, '/');
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

    private function downloadSftp(BackupDestination $destination, string $key, string $targetPath): void
    {
        $sftp = $this->sftp($destination);
        $remotePath = $this->joinAbsolute((string) $destination->setting('remote_path', '/'), $key);

        if (! $sftp->get($remotePath, $targetPath)) {
            throw new RuntimeException('Unable to download file over SFTP.');
        }
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
        $this->outboundHostGuard->assertHostAllowed($host);

        $key = $this->newSftp($host, $port)->getServerPublicHostKey();

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Unable to read the host key from the SSH server.');
        }

        return [
            'key' => $key,
            'fingerprint' => self::hostKeyFingerprint($key),
        ];
    }

    protected function newSftp(string $host, int $port): SFTP
    {
        return new SFTP($host, $port, 15);
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
        $pinned = trim($pinned);
        $presentedBlob = self::hostKeyBlob($presented);

        if ($presentedBlob === '') {
            return false;
        }

        if (preg_match('/^sha256:/i', $pinned)) {
            $pinnedDigest = trim(substr($pinned, strlen('SHA256:')));
            $presentedDigest = substr(self::hostKeyFingerprint($presented), strlen('SHA256:'));

            return $pinnedDigest !== '' && hash_equals($presentedDigest, $pinnedDigest);
        }

        $pinnedBlob = self::hostKeyBlob($pinned);

        return $pinnedBlob !== '' && hash_equals($pinnedBlob, $presentedBlob);
    }

    /**
     * SHA256 fingerprint of a host key, in OpenSSH's "SHA256:<base64>" form
     * (matching `ssh-keygen -lf`). Empty string when no key blob is found.
     */
    public static function hostKeyFingerprint(string $key): string
    {
        $blob = self::hostKeyBlob($key);

        if ($blob === '') {
            return '';
        }

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    /**
     * Extract the raw (base64-decoded) key blob from an OpenSSH key line or a
     * bare base64 string. The key type ("ssh-rsa") and any comment are not
     * valid base64 in strict mode, so we keep the first part that decodes to a
     * plausible key.
     */
    private static function hostKeyBlob(string $key): string
    {
        foreach (preg_split('/\s+/', trim($key)) ?: [] as $part) {
            $decoded = base64_decode($part, true);

            if ($decoded !== false && strlen($decoded) > 8) {
                return $decoded;
            }
        }

        return '';
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

            $path = $this->joinAbsolute($directory, $name);
            $key = $this->joinRelative($prefix, $name);

            if ($this->sftpEntryIsDirectory($sftp, $path, $attributes)) {
                $this->collectSftpFiles($sftp, $path, $key, $onObject, $limit, $count);

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

    /**
     * Prefer the type carried by rawlist over a per-entry network is_dir() round-trip.
     */
    private function sftpEntryIsDirectory(SFTP $sftp, string $path, array $attributes): bool
    {
        if (isset($attributes['type'])) {
            return (int) $attributes['type'] === self::SFTP_TYPE_DIRECTORY;
        }

        return $sftp->is_dir($path);
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

            $response = $this->azureRequest($destination, 'GET', '', $query);
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
        $this->azureRequest($destination, 'PUT', $filename, [], [
            'x-ms-blob-type' => 'BlockBlob',
            'Content-Type' => 'application/octet-stream',
        ], File::get($sourcePath));

        return $filename;
    }

    private function downloadAzure(BackupDestination $destination, string $key, string $targetPath): void
    {
        $this->azureRequest($destination, 'GET', $key, [], [], null, $targetPath);
    }

    private function azureRequest(BackupDestination $destination, string $method, string $path = '', array $query = [], array $headers = [], ?string $body = null, ?string $sink = null): Response
    {
        $config = $this->azureConfig($destination);
        $path = ltrim($path, '/');
        $url = rtrim($config['endpoint'], '/').'/'.$config['container'].($path !== '' ? '/'.str_replace('%2F', '/', rawurlencode($path)) : '');

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
            $headers['Authorization'] = $this->azureAuthorization($method, $config, $path, $query, $headers, $body);
        }

        $options = [];
        if ($body !== null) {
            $options['body'] = $body;
        }
        if ($sink) {
            $options['sink'] = $sink;
        }

        $response = Http::withHeaders($headers)->send($method, $url, $options);

        if ($response->failed()) {
            throw new RuntimeException('Azure Blob request failed with HTTP '.$response->status().'.');
        }

        return $response;
    }

    private function azureAuthorization(string $method, array $config, string $path, array $query, array $headers, ?string $body): string
    {
        $canonicalHeaders = collect($headers)
            ->filter(fn (mixed $value, string $key) => str_starts_with(strtolower($key), 'x-ms-'))
            ->mapWithKeys(fn (mixed $value, string $key) => [strtolower($key) => trim((string) $value)])
            ->sortKeys()
            ->map(fn (string $value, string $key) => $key.':'.$value."\n")
            ->implode('');

        $canonicalResource = '/'.$config['account'].'/'.$config['container'].($path !== '' ? '/'.$path : '');
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

                $objects[] = [
                    'key' => $entry['path_display'],
                    'display_name' => ltrim(Str::after($entry['path_display'], $path ?: '/'), '/'),
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

        return (string) ($response->json('path_display') ?: $path);
    }

    private function downloadDropbox(BackupDestination $destination, string $key, string $targetPath): void
    {
        $response = Http::withToken($this->dropboxToken($destination))
            ->withHeaders(['Dropbox-API-Arg' => json_encode(['path' => $key])])
            ->send('POST', 'https://content.dropboxapi.com/2/files/download', ['sink' => $targetPath]);

        $this->ensureDropboxOk($response);
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
            ->send('POST', 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true', ['body' => $body]);

        if ($response->failed()) {
            throw new RuntimeException('Google Drive upload failed with HTTP '.$response->status().'.');
        }

        return 'gdrive:'.$response->json('id');
    }

    private function downloadGoogleDrive(BackupDestination $destination, string $key, string $targetPath): void
    {
        $id = Str::startsWith($key, 'gdrive:') ? Str::after($key, 'gdrive:') : $key;
        $response = Http::withToken($this->googleDriveToken($destination))
            ->send('GET', $this->googleDriveEndpoint($destination).'/files/'.$id.'?alt=media&supportsAllDrives=true', ['sink' => $targetPath]);

        if ($response->failed()) {
            throw new RuntimeException('Google Drive download failed with HTTP '.$response->status().'.');
        }
    }

    private function googleDriveToken(BackupDestination $destination): string
    {
        $credentials = json_decode((string) $destination->secret('credentials_json'), true, flags: JSON_THROW_ON_ERROR);
        $tokenUrl = (string) ($destination->setting('token_url') ?: ($credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token'));
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
        return rtrim((string) ($destination->setting('endpoint') ?: 'https://www.googleapis.com/drive/v3'), '/');
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function testLocal(BackupDestination $destination): void
    {
        $path = (string) $destination->setting('archive_path');

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
            if (! $file->isFile()) {
                continue;
            }

            if (count($objects) >= $limit) {
                break;
            }

            $relative = ltrim(Str::after($file->getPathname(), $base), '/');

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
        $key = $this->joinRelative($directory, $filename);
        $target = $this->joinAbsolute((string) $destination->setting('archive_path'), $key);
        File::ensureDirectoryExists(dirname($target));
        File::copy($sourcePath, $target);

        return $key;
    }

    private function downloadLocal(BackupDestination $destination, string $key, string $targetPath): void
    {
        $source = $this->joinAbsolute((string) $destination->setting('archive_path'), $key);

        if (! File::exists($source)) {
            throw new RuntimeException('Local backup file does not exist.');
        }

        File::copy($source, $targetPath);
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

        // The archive dir is passed as a positional argument ($1), never
        // interpolated into the script, so it cannot break out of the command.
        $script = 'set -e; mkdir -p "$1"; probe="$1/.volumevault-write-test"; : > "$probe"; rm -f "$probe"';
        $command = [
            'docker', 'run', '--rm',
            '-v', $volume.':'.DockerVolumeName::MOUNT_POINT,
            '--entrypoint', 'sh',
            RunBackupContainer::IMAGE,
            '-c', $script, 'sh', $dir,
        ];

        $result = $this->dockerProcess->run($command, 120);

        if (! $result->successful()) {
            throw new RuntimeException('Docker volume destination is not usable: '.($result->combinedOutput() ?: 'unknown error'));
        }
    }

    /**
     * List backup objects from a read-only mount of the volume. `|| true` keeps
     * an absent sub-directory (nothing backed up yet) from being a hard error,
     * matching the "empty listing is fine" behaviour of the other providers.
     */
    private function listDockerVolume(BackupDestination $destination, int $limit = 1000): array
    {
        [$volume, $dir] = $this->dockerVolumeTarget($destination);

        $script = 'find "$1" -type f -exec stat -c "%s|%Y|%n" {} + 2>/dev/null || true';
        $command = [
            'docker', 'run', '--rm',
            '-v', $volume.':'.DockerVolumeName::MOUNT_POINT.':ro',
            '--entrypoint', 'sh',
            RunBackupContainer::IMAGE,
            '-c', $script, 'sh', $dir,
        ];

        $result = $this->dockerProcess->run($command, 120);

        if (! $result->successful()) {
            throw new RuntimeException('Unable to list the Docker volume destination: '.($result->combinedOutput() ?: 'unknown error'));
        }

        $objects = [];

        foreach (preg_split('/\R/', trim($result->output)) ?: [] as $line) {
            if (count($objects) >= $limit) {
                break;
            }

            if ($line === '') {
                continue;
            }

            $parts = explode('|', $line, 3);

            if (count($parts) < 3) {
                continue;
            }

            [$size, $mtime, $path] = $parts;
            $relative = ltrim(Str::after($path, $dir), '/');

            if ($relative === '') {
                continue;
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
    private function downloadDockerVolume(BackupDestination $destination, string $key, string $targetPath): void
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

        $result = $this->dockerProcess->runWithOutputFile($command, $targetPath, 0);

        if (! $result->successful()) {
            if (File::exists($targetPath)) {
                File::delete($targetPath);
            }

            throw new RuntimeException('Unable to download from the Docker volume destination: '.($result->errorOutput ?: 'unknown error'));
        }
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
