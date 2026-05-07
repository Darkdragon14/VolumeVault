<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    ];

    protected $fillable = [
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
            default => $this->bucket,
        };
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

    public function safeForFrontend(): array
    {
        return [
            'id' => $this->id,
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
