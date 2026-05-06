<?php

namespace App\Actions\Destinations;

use App\Models\BackupDestination;
use Illuminate\Support\Str;

class NormalizeDestinationData
{
    public function handle(array $data, ?BackupDestination $destination = null): array
    {
        $provider = (string) $data['provider'];
        $providerChanged = $destination && $destination->provider !== $provider;
        $settings = $data['settings'] ?? [];
        $submittedSecrets = $this->removeBlankSecrets($data['secrets'] ?? []);

        if (in_array($provider, BackupDestination::S3_PROVIDERS, true)) {
            $settings = $this->mergeS3Settings($settings, $data);
            $submittedSecrets = $this->mergeS3Secrets($submittedSecrets, $data);
        }

        $settings = $this->onlyProviderSettings($provider, $settings);
        $submittedSecrets = $this->onlyProviderSecrets($provider, $submittedSecrets);

        $existingSettings = $destination && ! $providerChanged ? ($destination->settings ?: []) : [];
        $existingSecrets = $destination && ! $providerChanged ? ($destination->secrets ?: []) : [];

        if ($destination && ! $providerChanged && $destination->isS3Compatible()) {
            $existingSecrets = array_replace([
                'access_key_id' => $destination->access_key_id,
                'secret_access_key' => $destination->secret_access_key,
            ], $existingSecrets);
        }

        $settings = $this->removeBlankSettings(array_replace($existingSettings, $settings));
        $secrets = array_replace($existingSecrets, $submittedSecrets);
        $legacy = $this->legacyColumns($provider, $settings);

        $payload = [
            'name' => $data['name'],
            'provider' => $provider,
            'endpoint' => $legacy['endpoint'],
            'region' => $legacy['region'],
            'bucket' => $this->limitLegacyString($legacy['bucket'] ?: $data['name']),
            'path_prefix' => $legacy['path_prefix'],
            'use_path_style_endpoint' => (bool) ($settings['use_path_style_endpoint'] ?? $data['use_path_style_endpoint'] ?? false),
            'settings' => $settings ?: null,
            'secrets' => $secrets ?: null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];

        if (in_array($provider, BackupDestination::S3_PROVIDERS, true)) {
            $payload['access_key_id'] = (string) ($secrets['access_key_id'] ?? '');
            $payload['secret_access_key'] = (string) ($secrets['secret_access_key'] ?? '');
        } elseif (! $destination || $providerChanged) {
            $payload['access_key_id'] = '';
            $payload['secret_access_key'] = '';
        }

        return $payload;
    }

    private function mergeS3Settings(array $settings, array $data): array
    {
        foreach (['endpoint', 'region', 'bucket', 'path_prefix', 'use_path_style_endpoint'] as $key) {
            if (array_key_exists($key, $data)) {
                $settings[$key] = $data[$key];
            }
        }

        return $settings;
    }

    private function mergeS3Secrets(array $secrets, array $data): array
    {
        foreach (['access_key_id', 'secret_access_key'] as $key) {
            if (filled($data[$key] ?? null)) {
                $secrets[$key] = $data[$key];
            }
        }

        return $secrets;
    }

    private function removeBlankSettings(array $settings): array
    {
        return collect($settings)
            ->reject(fn (mixed $value) => $value === null || $value === '')
            ->all();
    }

    private function removeBlankSecrets(array $secrets): array
    {
        return collect($secrets)
            ->reject(fn (mixed $value) => $value === null || $value === '')
            ->all();
    }

    private function onlyProviderSettings(string $provider, array $settings): array
    {
        $allowed = match ($provider) {
            BackupDestination::PROVIDER_AWS_S3,
            BackupDestination::PROVIDER_CLOUDFLARE_R2,
            BackupDestination::PROVIDER_CUSTOM_S3 => ['endpoint', 'region', 'bucket', 'path_prefix', 'use_path_style_endpoint'],
            BackupDestination::PROVIDER_WEBDAV => ['url', 'path', 'insecure'],
            BackupDestination::PROVIDER_SSH => ['host', 'port', 'remote_path', 'identity_file'],
            BackupDestination::PROVIDER_AZURE_BLOB => ['account_name', 'container', 'endpoint', 'access_tier'],
            BackupDestination::PROVIDER_DROPBOX => ['remote_path', 'concurrency_level'],
            BackupDestination::PROVIDER_GOOGLE_DRIVE => ['folder_id', 'impersonate_subject', 'endpoint', 'token_url'],
            BackupDestination::PROVIDER_LOCAL => ['archive_path', 'archive_mount_source'],
            default => [],
        };

        return collect($settings)->only($allowed)->all();
    }

    private function onlyProviderSecrets(string $provider, array $secrets): array
    {
        return collect($secrets)->only(BackupDestination::SECRET_FIELDS[$provider] ?? [])->all();
    }

    private function legacyColumns(string $provider, array $settings): array
    {
        return match ($provider) {
            BackupDestination::PROVIDER_AWS_S3,
            BackupDestination::PROVIDER_CLOUDFLARE_R2,
            BackupDestination::PROVIDER_CUSTOM_S3 => [
                'endpoint' => $settings['endpoint'] ?? null,
                'region' => $settings['region'] ?? null,
                'bucket' => $settings['bucket'] ?? null,
                'path_prefix' => $settings['path_prefix'] ?? null,
            ],
            BackupDestination::PROVIDER_WEBDAV => [
                'endpoint' => $settings['url'] ?? null,
                'region' => null,
                'bucket' => $settings['path'] ?? $settings['url'] ?? null,
                'path_prefix' => $settings['path'] ?? null,
            ],
            BackupDestination::PROVIDER_SSH => [
                'endpoint' => $settings['host'] ?? null,
                'region' => null,
                'bucket' => trim(($settings['host'] ?? '').':'.($settings['remote_path'] ?? ''), ':'),
                'path_prefix' => $settings['remote_path'] ?? null,
            ],
            BackupDestination::PROVIDER_AZURE_BLOB => [
                'endpoint' => $settings['endpoint'] ?? null,
                'region' => null,
                'bucket' => $settings['container'] ?? null,
                'path_prefix' => null,
            ],
            BackupDestination::PROVIDER_DROPBOX => [
                'endpoint' => null,
                'region' => null,
                'bucket' => $settings['remote_path'] ?? 'Dropbox',
                'path_prefix' => $settings['remote_path'] ?? null,
            ],
            BackupDestination::PROVIDER_GOOGLE_DRIVE => [
                'endpoint' => $settings['endpoint'] ?? null,
                'region' => null,
                'bucket' => $settings['folder_id'] ?? null,
                'path_prefix' => null,
            ],
            BackupDestination::PROVIDER_LOCAL => [
                'endpoint' => null,
                'region' => null,
                'bucket' => $settings['archive_path'] ?? null,
                'path_prefix' => $settings['archive_path'] ?? null,
            ],
            default => ['endpoint' => null, 'region' => null, 'bucket' => null, 'path_prefix' => null],
        };
    }

    private function limitLegacyString(?string $value): string
    {
        return Str::of((string) $value)->limit(250, '')->toString();
    }
}
