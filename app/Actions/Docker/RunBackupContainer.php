<?php

namespace App\Actions\Docker;

use App\Models\BackupDestination;
use App\Models\BackupRun;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class RunBackupContainer
{
    public const IMAGE = 'offen/docker-volume-backup:latest';

    public function __construct(private readonly DockerProcess $dockerProcess) {}

    public function handle(BackupRun $run): DockerProcessResult
    {
        $run->loadMissing('job.destination');

        $containerName = 'volumevault-backup-'.$run->id.'-'.Str::lower(Str::random(8));
        $runtime = $this->runtime($run);
        $environment = $runtime['environment'];
        $command = [
            'docker',
            'run',
            '--rm',
            '--name',
            $containerName,
            '--entrypoint',
            '/usr/bin/backup',
            '-v',
            $run->job->volume_name.':/backup/'.$this->safeMountName($run->job->volume_name).':ro',
            '-v',
            '/var/run/docker.sock:/var/run/docker.sock:ro',
        ];

        foreach ($runtime['mounts'] as $mount) {
            $command[] = '-v';
            $command[] = $mount;
        }

        foreach (array_keys($environment) as $key) {
            $command[] = '--env';
            $command[] = $key;
        }

        $command[] = self::IMAGE;

        $run->forceFill(['docker_container_id' => $containerName])->save();

        try {
            return $this->dockerProcess->run($command, 0, $environment);
        } finally {
            foreach ($runtime['cleanup'] as $path) {
                File::delete($path);
            }
        }
    }

    private function runtime(BackupRun $run): array
    {
        $job = $run->job;
        $destination = $job->destination;
        $safeVolume = $this->safeMountName($job->volume_name);

        // offen/docker-volume-backup controls destinations and archive behavior through env vars.
        // Check offen/docker-volume-backup documentation if an environment variable changes.
        $environment = [
            'BACKUP_FILENAME' => 'volumevault-'.$safeVolume.'-run-'.$run->id.'.tar.gz',
            'BACKUP_SOURCES' => '/backup',
            'EXEC_FORWARD_OUTPUT' => 'true',
        ];

        if ($job->retention_days) {
            $environment['BACKUP_RETENTION_DAYS'] = (string) $job->retention_days;
        }

        if ($job->retention_count) {
            $environment['BACKUP_RETENTION_COUNT'] = (string) $job->retention_count;
        }

        if (filled($job->backup_exclude_regexp)) {
            $environment['BACKUP_EXCLUDE_REGEXP'] = (string) $job->backup_exclude_regexp;
        }

        $runtime = $this->destinationRuntime($destination, $run);

        return [
            'environment' => array_merge($environment, $runtime['environment']),
            'mounts' => $runtime['mounts'],
            'cleanup' => $runtime['cleanup'],
        ];
    }

    private function destinationRuntime(BackupDestination $destination, BackupRun $run): array
    {
        $runtime = ['environment' => [], 'mounts' => [], 'cleanup' => []];

        match ($destination->provider) {
            BackupDestination::PROVIDER_AWS_S3,
            BackupDestination::PROVIDER_CLOUDFLARE_R2,
            BackupDestination::PROVIDER_CUSTOM_S3 => $runtime['environment'] = array_merge($this->s3Environment($destination), $this->endpointEnvironment($destination)),
            BackupDestination::PROVIDER_WEBDAV => $runtime['environment'] = $this->webDavEnvironment($destination),
            BackupDestination::PROVIDER_SSH => $runtime = $this->sshRuntime($destination, $run),
            BackupDestination::PROVIDER_AZURE_BLOB => $runtime['environment'] = $this->azureEnvironment($destination),
            BackupDestination::PROVIDER_DROPBOX => $runtime['environment'] = $this->dropboxEnvironment($destination),
            BackupDestination::PROVIDER_GOOGLE_DRIVE => $runtime['environment'] = $this->googleDriveEnvironment($destination),
            BackupDestination::PROVIDER_LOCAL => $runtime = $this->localRuntime($destination),
            default => throw new \RuntimeException('Unsupported backup destination provider.'),
        };

        return $runtime;
    }

    private function s3Environment(BackupDestination $destination): array
    {
        return array_filter([
            'AWS_S3_BUCKET_NAME' => $destination->setting('bucket'),
            'AWS_ACCESS_KEY_ID' => $destination->secret('access_key_id'),
            'AWS_SECRET_ACCESS_KEY' => $destination->secret('secret_access_key'),
            'AWS_REGION' => $destination->setting('region') ?: 'us-east-1',
            'AWS_S3_PATH' => filled($destination->setting('path_prefix')) ? trim((string) $destination->setting('path_prefix'), '/') : null,
        ], fn (mixed $value) => filled($value));
    }

    private function webDavEnvironment(BackupDestination $destination): array
    {
        return array_filter([
            'WEBDAV_URL' => $destination->setting('url'),
            'WEBDAV_PATH' => $destination->setting('path'),
            'WEBDAV_USERNAME' => $destination->secret('username'),
            'WEBDAV_PASSWORD' => $destination->secret('password'),
            'WEBDAV_URL_INSECURE' => $destination->setting('insecure') ? 'true' : null,
        ], fn (mixed $value) => filled($value));
    }

    private function sshRuntime(BackupDestination $destination, BackupRun $run): array
    {
        $environment = array_filter([
            'SSH_HOST_NAME' => $destination->setting('host'),
            'SSH_PORT' => (string) ($destination->setting('port') ?: 22),
            'SSH_REMOTE_PATH' => $destination->setting('remote_path'),
            'SSH_USER' => $destination->secret('user'),
            'SSH_PASSWORD' => $destination->secret('password'),
            'SSH_IDENTITY_PASSPHRASE' => $destination->secret('private_key_passphrase'),
        ], fn (mixed $value) => filled($value));

        $mounts = [];
        $cleanup = [];

        if (filled($destination->secret('private_key'))) {
            $keyPath = $this->writeSecretFile($run, 'ssh-key', (string) $destination->secret('private_key'));
            $environment['SSH_IDENTITY_FILE'] = '/run/secrets/volumevault_ssh_key';
            $mounts[] = $keyPath.':/run/secrets/volumevault_ssh_key:ro';
            $cleanup[] = $keyPath;
        } elseif (filled($destination->setting('identity_file'))) {
            $environment['SSH_IDENTITY_FILE'] = $destination->setting('identity_file');
        }

        return compact('environment', 'mounts', 'cleanup');
    }

    private function azureEnvironment(BackupDestination $destination): array
    {
        return array_filter([
            'AZURE_STORAGE_ACCOUNT_NAME' => $destination->setting('account_name'),
            'AZURE_STORAGE_PRIMARY_ACCOUNT_KEY' => $destination->secret('account_key'),
            'AZURE_STORAGE_CONNECTION_STRING' => $destination->secret('connection_string'),
            'AZURE_STORAGE_CONTAINER_NAME' => $destination->setting('container'),
            'AZURE_STORAGE_ENDPOINT' => $destination->setting('endpoint'),
            'AZURE_STORAGE_ACCESS_TIER' => $destination->setting('access_tier'),
        ], fn (mixed $value) => filled($value));
    }

    private function dropboxEnvironment(BackupDestination $destination): array
    {
        return array_filter([
            'DROPBOX_REMOTE_PATH' => $destination->setting('remote_path'),
            'DROPBOX_APP_KEY' => $destination->secret('app_key'),
            'DROPBOX_APP_SECRET' => $destination->secret('app_secret'),
            'DROPBOX_REFRESH_TOKEN' => $destination->secret('refresh_token'),
            'DROPBOX_CONCURRENCY_LEVEL' => $destination->setting('concurrency_level'),
        ], fn (mixed $value) => filled($value));
    }

    private function googleDriveEnvironment(BackupDestination $destination): array
    {
        return array_filter([
            'GOOGLE_DRIVE_CREDENTIALS_JSON' => $destination->secret('credentials_json'),
            'GOOGLE_DRIVE_FOLDER_ID' => $destination->setting('folder_id'),
            'GOOGLE_DRIVE_IMPERSONATE_SUBJECT' => $destination->setting('impersonate_subject'),
            'GOOGLE_DRIVE_ENDPOINT' => $destination->setting('endpoint'),
            'GOOGLE_DRIVE_TOKEN_URL' => $destination->setting('token_url'),
        ], fn (mixed $value) => filled($value));
    }

    private function localRuntime(BackupDestination $destination): array
    {
        $archivePath = rtrim((string) $destination->setting('archive_path'), '/');
        $mountSource = rtrim((string) ($destination->setting('archive_mount_source') ?: $archivePath), '/');

        return [
            'environment' => ['BACKUP_ARCHIVE' => $archivePath],
            'mounts' => [$mountSource.':'.$archivePath],
            'cleanup' => [],
        ];
    }

    private function writeSecretFile(BackupRun $run, string $name, string $contents): string
    {
        $directory = storage_path('app/docker-secrets');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/backup-'.$run->id.'-'.$name.'-'.Str::lower(Str::random(8));
        File::put($path, $contents);
        chmod($path, 0600);

        return $path;
    }

    private function endpointEnvironment(BackupDestination $destination): array
    {
        if (! filled($destination->endpoint)) {
            return [];
        }

        $endpoint = (string) $destination->endpoint;
        $parts = parse_url($endpoint);

        if (! is_array($parts) || empty($parts['host'])) {
            return ['AWS_ENDPOINT' => $endpoint];
        }

        $host = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = isset($parts['path']) ? trim($parts['path'], '/') : '';

        return array_filter([
            'AWS_ENDPOINT' => $path ? $host.'/'.$path : $host,
            'AWS_ENDPOINT_PROTO' => $parts['scheme'] ?? 'https',
        ]);
    }

    private function safeMountName(string $volumeName): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]+/', '_', $volumeName) ?: 'volume';
    }
}
