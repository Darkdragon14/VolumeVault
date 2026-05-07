<?php

namespace App\Services\BackupDestinations;

use App\Models\BackupDestination;
use App\Services\S3\S3ClientFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use RuntimeException;

class DestinationStorage
{
    public function __construct(private readonly S3ClientFactory $s3ClientFactory) {}

    public function test(BackupDestination $destination): void
    {
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
            default => throw new RuntimeException('Unsupported backup destination provider.'),
        };
    }

    public function listBackupObjects(BackupDestination $destination): array
    {
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
            default => throw new RuntimeException('Unsupported backup destination provider.'),
        };

        return collect($objects)
            ->filter(fn (array $object) => $this->plausibleBackupKey((string) ($object['display_name'] ?? $object['key'] ?? '')))
            ->sortByDesc('last_modified')
            ->values()
            ->all();
    }

    public function upload(BackupDestination $destination, string $sourcePath, string $filename, ?string $directory = null): string
    {
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
            default => throw new RuntimeException('Unsupported backup destination provider.'),
        };
    }

    public function download(BackupDestination $destination, string $key, string $targetPath): void
    {
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
            default => throw new RuntimeException('Unsupported backup destination provider.'),
        };
    }

    private function listS3(BackupDestination $destination, int $maxKeys = 1000): array
    {
        $result = $this->s3ClientFactory->make($destination)->listObjectsV2([
            'Bucket' => $destination->setting('bucket'),
            'Prefix' => trim((string) $destination->setting('path_prefix'), '/'),
            'MaxKeys' => $maxKeys,
        ]);

        return collect($result['Contents'] ?? [])
            ->map(fn (array $object) => [
                'key' => (string) $object['Key'],
                'display_name' => (string) $object['Key'],
                'size' => (int) ($object['Size'] ?? 0),
                'last_modified' => isset($object['LastModified']) ? $object['LastModified']->format(DATE_ATOM) : null,
            ])
            ->values()
            ->all();
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
        $sftp = $this->sftp($destination);
        $base = (string) $destination->setting('remote_path', '/');
        $objects = [];
        $this->collectSftpFiles($sftp, $base, '', $objects, $limit);

        return $objects;
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

    private function sftp(BackupDestination $destination): SFTP
    {
        $sftp = new SFTP((string) $destination->setting('host'), (int) $destination->setting('port', 22), 15);
        $credential = (string) $destination->secret('password', '');

        if (filled($destination->secret('private_key'))) {
            $credential = PublicKeyLoader::load((string) $destination->secret('private_key'), $destination->secret('private_key_passphrase') ?: false);
        }

        if (! $sftp->login((string) $destination->secret('user'), $credential)) {
            throw new RuntimeException('Unable to authenticate to the SFTP destination.');
        }

        return $sftp;
    }

    private function collectSftpFiles(SFTP $sftp, string $directory, string $prefix, array &$objects, int $limit): void
    {
        if (count($objects) >= $limit) {
            return;
        }

        $entries = $sftp->rawlist($directory) ?: [];

        foreach ($entries as $name => $attributes) {
            if ($name === '.' || $name === '..' || count($objects) >= $limit) {
                continue;
            }

            $path = $this->joinAbsolute($directory, $name);
            $key = $this->joinRelative($prefix, $name);

            if ($sftp->is_dir($path)) {
                $this->collectSftpFiles($sftp, $path, $key, $objects, $limit);

                continue;
            }

            $objects[] = [
                'key' => $key,
                'display_name' => $key,
                'size' => (int) ($attributes['size'] ?? 0),
                'last_modified' => isset($attributes['mtime']) ? date(DATE_ATOM, (int) $attributes['mtime']) : null,
            ];
        }
    }

    private function listAzure(BackupDestination $destination, int $limit = 1000): array
    {
        $response = $this->azureRequest($destination, 'GET', '', [
            'restype' => 'container',
            'comp' => 'list',
            'maxresults' => (string) $limit,
        ]);

        $xml = simplexml_load_string($response->body());

        if ($xml === false) {
            throw new RuntimeException('Unable to parse Azure Blob response.');
        }

        $objects = [];
        foreach ($xml->Blobs->Blob ?? [] as $blob) {
            $objects[] = [
                'key' => (string) $blob->Name,
                'display_name' => (string) $blob->Name,
                'size' => (int) $blob->Properties->{'Content-Length'},
                'last_modified' => date(DATE_ATOM, strtotime((string) $blob->Properties->{'Last-Modified'})),
            ];
        }

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
        $response = Http::withToken($this->googleDriveToken($destination))->get($this->googleDriveEndpoint($destination).'/files', [
            'q' => "'".$destination->setting('folder_id')."' in parents and trashed = false",
            'fields' => 'files(id,name,size,modifiedTime,mimeType)',
            'orderBy' => 'modifiedTime desc',
            'pageSize' => $limit,
            'supportsAllDrives' => 'true',
            'includeItemsFromAllDrives' => 'true',
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Google Drive request failed with HTTP '.$response->status().'.');
        }

        return collect($response->json('files') ?? [])
            ->reject(fn (array $file) => ($file['mimeType'] ?? null) === 'application/vnd.google-apps.folder')
            ->map(fn (array $file) => [
                'key' => 'gdrive:'.$file['id'],
                'display_name' => (string) $file['name'],
                'size' => (int) ($file['size'] ?? 0),
                'last_modified' => isset($file['modifiedTime']) ? date(DATE_ATOM, strtotime($file['modifiedTime'])) : null,
            ])
            ->values()
            ->all();
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

    private function listLocal(BackupDestination $destination): array
    {
        $this->testLocal($destination);
        $base = rtrim((string) $destination->setting('archive_path'), '/');

        return collect(File::allFiles($base))
            ->map(fn (\SplFileInfo $file) => [
                'key' => ltrim(Str::after($file->getPathname(), $base), '/'),
                'display_name' => ltrim(Str::after($file->getPathname(), $base), '/'),
                'size' => $file->getSize(),
                'last_modified' => date(DATE_ATOM, $file->getMTime()),
            ])
            ->values()
            ->all();
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
}
