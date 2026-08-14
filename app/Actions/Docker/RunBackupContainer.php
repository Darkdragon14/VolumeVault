<?php

namespace App\Actions\Docker;

use App\Actions\Backup\RenderBackupFilename;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Services\Backup\IncludePathsToExcludeRegexp;
use App\Services\BackupSources\HostPathPolicy;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use App\Services\Docker\DockerVolumeName;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class RunBackupContainer
{
    public const IMAGE = 'offen/docker-volume-backup:latest';

    private readonly HostPathPolicy $hostPathPolicy;

    private readonly RenderBackupFilename $renderBackupFilename;

    private readonly IncludePathsToExcludeRegexp $includePathsToExcludeRegexp;

    public function __construct(
        private readonly DockerProcess $dockerProcess,
        ?RenderBackupFilename $renderBackupFilename = null,
        ?HostPathPolicy $hostPathPolicy = null,
        ?IncludePathsToExcludeRegexp $includePathsToExcludeRegexp = null,
    ) {
        $this->renderBackupFilename = $renderBackupFilename ?? app(RenderBackupFilename::class);
        $this->hostPathPolicy = $hostPathPolicy ?? app(HostPathPolicy::class);
        $this->includePathsToExcludeRegexp = $includePathsToExcludeRegexp ?? app(IncludePathsToExcludeRegexp::class);
    }

    public function handle(BackupRun $run, ?callable $heartbeat = null): DockerProcessResult
    {
        $run->loadMissing('job.destination');

        $containerName = 'volumevault-backup-'.$run->id.'-'.Str::lower(Str::random(8));
        $runtime = $this->runtime($run);
        $environment = $runtime['environment'];
        $dockerHost = (string) config('volumevault.docker_host', 'unix:///var/run/docker.sock');
        $dockerNetwork = trim((string) config('volumevault.docker_network', ''));
        $environment['DOCKER_HOST'] = $dockerHost;
        $command = [
            'docker',
            'run',
            '--rm',
            '--name',
            $containerName,
            '--entrypoint',
            '/usr/bin/backup',
        ];

        if ($dockerNetwork !== '') {
            $command[] = '--network';
            $command[] = $dockerNetwork;
        }

        foreach ($this->sourceMountArguments($run->job) as $argument) {
            $command[] = $argument;
        }

        if (str_starts_with($dockerHost, 'unix://')) {
            $socketPath = substr($dockerHost, strlen('unix://'));

            if ($socketPath !== '') {
                $command[] = '-v';
                $command[] = $socketPath.':'.$socketPath.':ro';
            }
        }

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
            $execute = fn (): DockerProcessResult => $this->dockerProcess->run($command, 0, $environment);

            return $heartbeat === null
                ? $execute()
                : $this->dockerProcess->whileMonitoring($heartbeat, $execute);
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

        $this->assertSourceAndDestinationDiffer($job, $destination);

        // offen/docker-volume-backup controls destinations and archive behavior through env vars.
        // Check offen/docker-volume-backup documentation if an environment variable changes.
        $environment = [
            'BACKUP_FILENAME' => $this->backupFilename($run),
            'BACKUP_SOURCES' => '/backup',
            'EXEC_FORWARD_OUTPUT' => 'true',
        ];

        if ($job->retention_days) {
            $environment['BACKUP_RETENTION_DAYS'] = (string) $job->retention_days;
        }

        if ($job->retention_count) {
            $environment['BACKUP_RETENTION_COUNT'] = (string) $job->retention_count;
        }

        if ($job->backup_filter_mode === BackupJob::FILTER_MODE_INCLUDE) {
            $paths = preg_split('/\s*,\s*/', (string) $job->backup_include_paths, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            // offen only supports exclusion, so translate the "keep only these paths"
            // intent into the equivalent exclude regexp against the mounted paths.
            $exclude = $this->includePathsToExcludeRegexp->build('/backup/'.$this->sourceMountName($job), $paths);

            if (filled($exclude)) {
                $environment['BACKUP_EXCLUDE_REGEXP'] = $exclude;
            }
        } elseif (filled($job->backup_exclude_regexp)) {
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
            BackupDestination::PROVIDER_DOCKER_VOLUME => $runtime = $this->dockerVolumeRuntime($destination),
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

        // The local destination is bind-mounted read-write (offen writes the
        // archive into it), so an unrestricted path would let the backup
        // container write anywhere on the host. Re-validate both ends against
        // the host-path allowlist at run time; local paths are canonicalized.
        $this->hostPathPolicy->assertValidAtRuntime($archivePath);
        $this->hostPathPolicy->assertValidAtRuntime($mountSource);

        return [
            'environment' => ['BACKUP_ARCHIVE' => $archivePath],
            'mounts' => [$mountSource.':'.$archivePath],
            'cleanup' => [],
        ];
    }

    private function dockerVolumeRuntime(BackupDestination $destination): array
    {
        // Mount the named Docker volume into the Offen container by name and let
        // Offen write the archive into it. Re-validate the name and sub-path at
        // run time (fail-closed + TOCTOU): an unconstrained value could turn the
        // `-v` spec into a host bind mount or inject extra mount options. Unlike
        // the local provider, no host path is touched, so the volume is sandboxed.
        $volume = DockerVolumeName::assertName((string) $destination->setting('volume_name'));

        // `docker run -v <name>:...` silently re-creates a missing named volume
        // as an empty `local` volume. Without this guard, a volume that was
        // deleted (e.g. an NFS share removed from Compose) would let the backup
        // "succeed" into the wrong, empty volume. Fail the run instead — it is
        // surfaced and notified like any other backup error.
        $this->assertVolumeExists($volume);

        $archivePath = DockerVolumeName::archiveDir((string) $destination->setting('path_prefix'));

        return [
            'environment' => ['BACKUP_ARCHIVE' => $archivePath],
            'mounts' => [$volume.':'.DockerVolumeName::MOUNT_POINT],
            'cleanup' => [],
        ];
    }

    private function assertVolumeExists(string $volume): void
    {
        $result = $this->dockerProcess->run(['docker', 'volume', 'inspect', $volume], 60);

        if (! $result->successful()) {
            throw new \RuntimeException('The Docker volume "'.$volume.'" does not exist. Create it (for example in your Compose file) before running backups.');
        }
    }

    /**
     * Refuse to back a Docker volume up into itself. A docker_volume destination
     * pointing at the same volume the job archives would mount it at /archive
     * while it is also mounted read-only at /backup, so Offen would write the
     * growing archive into the very data it is archiving.
     */
    private function assertSourceAndDestinationDiffer(BackupJob $job, BackupDestination $destination): void
    {
        if ($destination->provider !== BackupDestination::PROVIDER_DOCKER_VOLUME || $job->isHostPathSource()) {
            return;
        }

        $sourceVolume = (string) $job->volume_name;
        $destinationVolume = (string) $destination->setting('volume_name');

        if ($sourceVolume !== '' && $sourceVolume === $destinationVolume) {
            throw new \RuntimeException('The Docker volume destination "'.$destinationVolume.'" is the same volume being backed up; choose a different destination volume.');
        }
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

    public function backupFilename(BackupRun $run): string
    {
        return $this->renderBackupFilename->handle($run);
    }

    private function sourceMountArguments(BackupJob $job): array
    {
        $target = '/backup/'.$this->sourceMountName($job);

        if ($job->isHostPathSource()) {
            // Re-validate at run time, not just at job creation. Local paths are
            // also canonicalized to detect a symlink swapped after validation.
            $this->hostPathPolicy->assertValidAtRuntime((string) $job->host_path);

            return [
                '--mount',
                'type=bind,src='.$job->host_path.',dst='.$target.',readonly',
            ];
        }

        return [
            '-v',
            $job->volume_name.':'.$target.':ro',
        ];
    }

    private function sourceMountName(BackupJob $job): string
    {
        $sourceName = $job->isHostPathSource()
            ? trim($job->sourceName(), '/')
            : $job->sourceName();

        return $this->safeMountName($sourceName);
    }

    private function safeMountName(string $volumeName): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]+/', '_', $volumeName) ?: 'volume';
    }
}
