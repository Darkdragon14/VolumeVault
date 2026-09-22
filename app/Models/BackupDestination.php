<?php

namespace App\Models;

use App\Support\SshHostKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class BackupDestination extends Model
{
    use HasFactory;

    public const PROVIDER_AWS_S3 = 'aws_s3';

    public const PROVIDER_CLOUDFLARE_R2 = 'cloudflare_r2';

    public const PROVIDER_CUSTOM_S3 = 'custom_s3';

    public const PROVIDER_WEBDAV = 'webdav';

    public const PROVIDER_SSH = 'ssh';

    public const PROVIDER_AZURE_BLOB = 'azure_blob';

    public const PROVIDER_DROPBOX = 'dropbox';

    public const PROVIDER_GOOGLE_DRIVE = 'google_drive';

    public const PROVIDER_LOCAL = 'local';

    public const PROVIDER_DOCKER_VOLUME = 'docker_volume';

    public const S3_PROVIDERS = [
        self::PROVIDER_AWS_S3,
        self::PROVIDER_CLOUDFLARE_R2,
        self::PROVIDER_CUSTOM_S3,
    ];

    public const PROVIDERS = [
        self::PROVIDER_AWS_S3,
        self::PROVIDER_CLOUDFLARE_R2,
        self::PROVIDER_CUSTOM_S3,
        self::PROVIDER_WEBDAV,
        self::PROVIDER_SSH,
        self::PROVIDER_AZURE_BLOB,
        self::PROVIDER_DROPBOX,
        self::PROVIDER_GOOGLE_DRIVE,
        self::PROVIDER_LOCAL,
        self::PROVIDER_DOCKER_VOLUME,
    ];

    public const PROVIDER_LABELS = [
        self::PROVIDER_AWS_S3 => 'AWS S3',
        self::PROVIDER_CLOUDFLARE_R2 => 'Cloudflare R2',
        self::PROVIDER_CUSTOM_S3 => 'Custom S3-compatible',
        self::PROVIDER_WEBDAV => 'WebDAV',
        self::PROVIDER_SSH => 'SSH/SFTP',
        self::PROVIDER_AZURE_BLOB => 'Azure Blob Storage',
        self::PROVIDER_DROPBOX => 'Dropbox',
        self::PROVIDER_GOOGLE_DRIVE => 'Google Drive',
        self::PROVIDER_LOCAL => 'Local filesystem',
        self::PROVIDER_DOCKER_VOLUME => 'Docker volume',
    ];

    public const SECRET_FIELDS = [
        self::PROVIDER_AWS_S3 => ['access_key_id', 'secret_access_key'],
        self::PROVIDER_CLOUDFLARE_R2 => ['access_key_id', 'secret_access_key'],
        self::PROVIDER_CUSTOM_S3 => ['access_key_id', 'secret_access_key'],
        self::PROVIDER_WEBDAV => ['username', 'password'],
        self::PROVIDER_SSH => ['user', 'password', 'private_key', 'private_key_passphrase'],
        self::PROVIDER_AZURE_BLOB => ['account_key', 'connection_string'],
        self::PROVIDER_DROPBOX => ['app_key', 'app_secret', 'refresh_token'],
        self::PROVIDER_GOOGLE_DRIVE => ['credentials_json'],
        self::PROVIDER_LOCAL => [],
        self::PROVIDER_DOCKER_VOLUME => [],
    ];

    protected $fillable = [
        'docker_host_id',
        'storage_measurement_host_id',
        'name',
        'provider',
        'endpoint',
        'region',
        'bucket',
        'path_prefix',
        'access_key_id',
        'secret_access_key',
        'use_path_style_endpoint',
        'settings',
        'secrets',
        'is_active',
        'last_tested_at',
        'last_test_status',
        'last_test_error',
    ];

    protected $hidden = [
        'access_key_id',
        'secret_access_key',
        'secrets',
    ];

    protected function casts(): array
    {
        return [
            'docker_host_id' => 'integer',
            'storage_measurement_host_id' => 'integer',
            'access_key_id' => 'encrypted',
            'secret_access_key' => 'encrypted',
            'use_path_style_endpoint' => 'boolean',
            'settings' => 'array',
            'secrets' => 'encrypted:array',
            'is_active' => 'boolean',
            'last_tested_at' => 'datetime',
        ];
    }

    public function isS3Compatible(): bool
    {
        return in_array($this->provider, self::S3_PROVIDERS, true);
    }

    protected static function booted(): void
    {
        static::saving(function (self $destination): void {
            $destination->docker_host_id = $destination->isHostBound()
                ? ($destination->docker_host_id ?? DockerHost::LOCAL_ID)
                : null;
            if ($destination->isHostBound()) {
                $destination->storage_measurement_host_id = $destination->docker_host_id;
            }
            if ($destination->exists) {
                $original = new self;
                $original->setRawAttributes($destination->getRawOriginal());
                if ($original->locatorFingerprint() !== $destination->locatorFingerprint()
                    || $original->storageMeasurementHostId() !== $destination->storageMeasurementHostId()) {
                    $destination->storage_measurement_revision = (string) \Illuminate\Support\Str::uuid();
                }
            }
        });
    }

    public function isHostBound(): bool
    {
        return in_array($this->provider, [self::PROVIDER_LOCAL, self::PROVIDER_DOCKER_VOLUME], true);
    }

    public function storageMeasurementHostId(): int
    {
        return (int) ($this->isHostBound() ? $this->docker_host_id : ($this->storage_measurement_host_id ?? DockerHost::LOCAL_ID));
    }

    public function storageMeasurementFingerprint(): string
    {
        return hash('sha256', $this->locatorFingerprint().':'.$this->storageMeasurementHostId().':'.$this->storage_measurement_revision);
    }

    public function dockerHost(): BelongsTo
    {
        return $this->belongsTo(DockerHost::class);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        $settings = $this->settings ?: [];

        if (array_key_exists($key, $settings)) {
            return $settings[$key];
        }

        if ($this->isS3Compatible()) {
            return match ($key) {
                'endpoint' => $this->endpoint,
                'region' => $this->region,
                'bucket' => $this->bucket,
                'path_prefix' => $this->path_prefix,
                'use_path_style_endpoint' => $this->use_path_style_endpoint,
                default => $default,
            };
        }

        return $default;
    }

    public function secret(string $key, mixed $default = null): mixed
    {
        $secrets = $this->secrets ?: [];

        if (array_key_exists($key, $secrets)) {
            return $secrets[$key];
        }

        if ($this->isS3Compatible()) {
            return match ($key) {
                'access_key_id' => $this->access_key_id,
                'secret_access_key' => $this->secret_access_key,
                default => $default,
            };
        }

        return $default;
    }

    public function locatorFingerprint(): string
    {
        $locator = match ($this->provider) {
            self::PROVIDER_AWS_S3,
            self::PROVIDER_CLOUDFLARE_R2,
            self::PROVIDER_CUSTOM_S3 => [
                'endpoint' => rtrim((string) $this->setting('endpoint'), '/'),
                'region' => strtolower(trim((string) $this->setting('region'))),
                'bucket' => trim((string) $this->setting('bucket')),
                'path_prefix' => trim((string) $this->setting('path_prefix'), '/'),
                'use_path_style_endpoint' => (bool) $this->setting('use_path_style_endpoint'),
            ],
            self::PROVIDER_WEBDAV => [
                'username' => trim((string) $this->secret('username')),
                'url' => rtrim((string) $this->setting('url'), '/'),
                'path' => trim((string) $this->setting('path'), '/'),
            ],
            self::PROVIDER_SSH => [
                'host' => strtolower(trim((string) $this->setting('host'))),
                'port' => (int) $this->setting('port', 22),
                'remote_path' => rtrim((string) $this->setting('remote_path', '/'), '/') ?: '/',
                'user' => trim((string) $this->secret('user')),
                'host_key_fingerprint' => SshHostKey::fingerprint((string) $this->setting('host_key')),
            ],
            self::PROVIDER_AZURE_BLOB => [
                'account_name' => $this->azureAccountName(),
                'container' => trim((string) $this->setting('container')),
                'endpoint' => $this->azureEndpoint(),
            ],
            self::PROVIDER_DROPBOX => [
                'remote_path' => mb_strtolower(trim((string) $this->setting('remote_path'), '/')),
                'account_credential_fingerprint' => hash('sha256', (string) $this->secret('refresh_token')),
            ],
            self::PROVIDER_GOOGLE_DRIVE => [
                'client_email' => $this->googleDriveClientEmail(),
                'folder_id' => trim((string) $this->setting('folder_id')),
                'impersonate_subject' => strtolower(trim((string) $this->setting('impersonate_subject'))),
                'endpoint' => rtrim((string) ($this->setting('endpoint') ?: 'https://www.googleapis.com/drive/v3'), '/'),
            ],
            self::PROVIDER_LOCAL => [
                'archive_path' => rtrim((string) $this->setting('archive_path'), '/'),
                'archive_mount_source' => rtrim((string) $this->setting('archive_mount_source'), '/'),
            ],
            self::PROVIDER_DOCKER_VOLUME => [
                'volume_name' => trim((string) $this->setting('volume_name')),
                'path_prefix' => trim((string) $this->setting('path_prefix'), '/'),
            ],
            default => [],
        };

        // Preserve persisted local fingerprints when upgrading existing runs.
        if ($this->isHostBound() && $this->docker_host_id !== null && $this->docker_host_id !== DockerHost::LOCAL_ID) {
            $locator['docker_host_id'] = $this->docker_host_id;
        }

        return hash('sha256', json_encode([
            'provider' => $this->provider,
            'locator' => $locator,
        ], JSON_THROW_ON_ERROR));
    }

    private function googleDriveClientEmail(): string
    {
        $credentials = json_decode((string) $this->secret('credentials_json'), true);

        return is_array($credentials) ? strtolower(trim((string) ($credentials['client_email'] ?? ''))) : '';
    }

    private function azureAccountName(): string
    {
        $accountName = trim((string) $this->setting('account_name'));

        if ($accountName !== '') {
            return strtolower($accountName);
        }

        preg_match('/(?:^|;)\s*AccountName=([^;]+)/i', (string) $this->secret('connection_string'), $matches);

        return strtolower(trim((string) ($matches[1] ?? '')));
    }

    private function azureEndpoint(): string
    {
        $endpoint = trim((string) $this->setting('endpoint'));
        $connectionString = (string) $this->secret('connection_string');

        if ($endpoint === '' && preg_match('/(?:^|;)\s*BlobEndpoint=([^;]+)/i', $connectionString, $matches)) {
            $endpoint = trim($matches[1]);
        }

        if ($endpoint === '') {
            preg_match('/(?:^|;)\s*DefaultEndpointsProtocol=([^;]+)/i', $connectionString, $protocolMatches);
            preg_match('/(?:^|;)\s*EndpointSuffix=([^;]+)/i', $connectionString, $suffixMatches);
            $accountName = $this->azureAccountName();

            if ($accountName !== '') {
                $endpoint = ($protocolMatches[1] ?? 'https').'://'.$accountName.'.'.($suffixMatches[1] ?? 'blob.core.windows.net');
            }
        }

        return rtrim($endpoint, '/');
    }

    public function targetLabel(): string
    {
        return match ($this->provider) {
            self::PROVIDER_AWS_S3, self::PROVIDER_CLOUDFLARE_R2, self::PROVIDER_CUSTOM_S3 => (string) $this->setting('bucket', $this->bucket),
            self::PROVIDER_WEBDAV => trim((string) $this->setting('path', '/')) ?: '/',
            self::PROVIDER_SSH => (string) $this->setting('remote_path', '/'),
            self::PROVIDER_AZURE_BLOB => (string) $this->setting('container', $this->bucket),
            self::PROVIDER_DROPBOX => (string) ($this->setting('remote_path') ?: '/'),
            self::PROVIDER_GOOGLE_DRIVE => (string) $this->setting('folder_id', $this->bucket),
            self::PROVIDER_LOCAL => (string) $this->setting('archive_path', $this->bucket),
            self::PROVIDER_DOCKER_VOLUME => $this->dockerVolumeTargetLabel(),
            default => $this->bucket,
        };
    }

    private function dockerVolumeTargetLabel(): string
    {
        $volume = (string) $this->setting('volume_name', $this->bucket);
        $prefix = trim((string) $this->setting('path_prefix'), '/');

        return $prefix !== '' ? $volume.'/'.$prefix : $volume;
    }

    public static function providerOptions(): array
    {
        return collect(self::PROVIDERS)
            ->map(fn (string $provider) => [
                'value' => $provider,
                'label' => self::PROVIDER_LABELS[$provider] ?? $provider,
                'secret_fields' => self::SECRET_FIELDS[$provider] ?? [],
            ])
            ->values()
            ->all();
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(BackupJob::class);
    }

    public function restoreRuns(): HasMany
    {
        return $this->hasMany(RestoreRun::class);
    }

    /**
     * Whether deleting this destination would break work still in flight. Deleting
     * it cascades its jobs (and their runs) and nulls restore_runs that read from
     * it, bypassing the per-job delete guard — so any of the following blocks it:
     *  - a backup run whose job is on this destination, in progress or still
     *    holding stopped containers;
     *  - a restore that reads from this destination, matched both by the run's own
     *    backup_destination_id (a restore whose job later moved elsewhere still
     *    references it) and by the job's current destination;
     *  - a queued/running group run with a member job on this destination, whose
     *    member jobs the cascade would delete mid-run.
     */
    public function hasRunInProgress(bool $includeAllFinalizations = false): bool
    {
        $ofThisDestination = fn ($query) => $query->where('backup_destination_id', $this->id);

        return BackupRun::query()
            ->where(function ($query) use ($ofThisDestination): void {
                $query->where('backup_destination_id_snapshot', $this->id)
                    ->orWhere(fn ($query) => $query
                        ->whereNull('backup_destination_id_snapshot')
                        ->whereHas('job', $ofThisDestination));
            })
            ->requiringDestinationConfiguration()
            ->exists()
            || ($includeAllFinalizations && BackupRun::query()
                ->where(fn ($query) => $query
                    ->where('backup_destination_id_snapshot', $this->id)
                    ->orWhereHas('job', $ofThisDestination))
                ->withOutstandingFinalizations()
                ->exists())
            || ($includeAllFinalizations && RestoreRun::query()
                ->where(fn ($query) => $query
                    ->where('backup_destination_id', $this->id)
                    ->orWhereHas('job', $ofThisDestination))
                ->withOutstandingFinalizations()
                ->exists())
            || $this->restoreRuns()->activeOrHoldingContainers()->exists()
            || RestoreRun::whereHas('job', $ofThisDestination)->activeOrHoldingContainers()->exists()
            || BackupGroupRun::query()
                ->whereIn('status', [BackupGroupRun::STATUS_QUEUED, BackupGroupRun::STATUS_RUNNING])
                ->whereHas('group.members', $ofThisDestination)
                ->exists();
    }

    /**
     * Whether in-flight work still reads this destination's current configuration.
     */
    public function hasConfigurationInUse(): bool
    {
        $ofThisDestination = fn ($query) => $query->where('backup_destination_id', $this->id);

        return BackupRun::query()
            ->where(function ($query) use ($ofThisDestination): void {
                $query->where('backup_destination_id_snapshot', $this->id)
                    ->orWhere(fn ($query) => $query
                        ->whereNull('backup_destination_id_snapshot')
                        ->whereHas('job', $ofThisDestination));
            })
            ->requiringDestinationConfiguration()
            ->exists()
            || $this->restoreRuns()->activeOrHoldingContainers()->exists()
            || RestoreRun::query()
                ->whereHas('job', $ofThisDestination)
                ->whereIn('status', [RestoreRun::STATUS_QUEUED, RestoreRun::STATUS_RUNNING])
                ->where('backup_before_overwrite', true)
                ->whereNull('pre_restore_backup_run_id')
                ->exists()
            || BackupGroupRun::query()
                ->whereIn('status', [BackupGroupRun::STATUS_QUEUED, BackupGroupRun::STATUS_RUNNING])
                ->whereHas('group.members', $ofThisDestination)
                ->exists();
    }

    public function alerts(): MorphMany
    {
        return $this->morphMany(Alert::class, 'subject');
    }

    public function safeForFrontend(): array
    {
        return [
            'id' => $this->id,
            'storage_measurement_host_id' => $this->storage_measurement_host_id,
            'name' => $this->name,
            'provider' => $this->provider,
            'provider_label' => self::PROVIDER_LABELS[$this->provider] ?? $this->provider,
            'endpoint' => $this->endpoint,
            'region' => $this->region,
            'bucket' => $this->bucket,
            'path_prefix' => $this->path_prefix,
            'settings' => $this->settings ?: [],
            'target_label' => $this->targetLabel(),
            'use_path_style_endpoint' => $this->use_path_style_endpoint,
            'is_active' => $this->is_active,
            'last_tested_at' => $this->last_tested_at,
            'last_test_status' => $this->last_test_status,
            'last_test_error' => $this->last_test_error,
            'has_secrets' => collect(self::SECRET_FIELDS[$this->provider] ?? [])
                ->mapWithKeys(fn (string $field) => [$field => filled($this->secret($field))])
                ->all(),
            'has_access_key_id' => filled($this->secret('access_key_id')) || filled($this->getRawOriginal('access_key_id')),
            'has_secret_access_key' => filled($this->secret('secret_access_key')) || filled($this->getRawOriginal('secret_access_key')),
            'masked_access_key_id' => '********',
            'masked_secret_access_key' => '********',
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
