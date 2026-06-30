<?php

namespace App\Http\Requests\Concerns;

use App\Models\BackupDestination;
use App\Services\BackupSources\HostPathPolicy;
use App\Services\Docker\DockerVolumeName;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesBackupDestination
{
    /**
     * Rules shared by the store and update destination requests.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function baseRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'provider' => ['required', 'string', Rule::in(BackupDestination::PROVIDERS)],
            'endpoint' => ['nullable', 'url', 'max:2048'],
            'region' => ['nullable', 'string', 'max:255'],
            'bucket' => ['nullable', 'string', 'max:255'],
            'path_prefix' => ['nullable', 'string', 'max:255'],
            'access_key_id' => ['nullable', 'string'],
            'secret_access_key' => ['nullable', 'string'],
            'use_path_style_endpoint' => ['boolean'],
            'is_active' => ['boolean'],
            'settings' => ['nullable', 'array'],
            'secrets' => ['nullable', 'array'],
            'settings.*' => ['nullable'],
            'secrets.*' => ['nullable'],
            'settings.storage_limit_warning_bytes' => ['nullable', 'integer', 'min:0'],
            'settings.storage_limit_critical_bytes' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * Provider-specific rules. When $secretsRequired is true (store), credentials are
     * mandatory; when false (update), they stay optional so existing secrets are kept.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function providerRules(bool $secretsRequired): array
    {
        return match ($this->input('provider')) {
            BackupDestination::PROVIDER_AWS_S3,
            BackupDestination::PROVIDER_CLOUDFLARE_R2,
            BackupDestination::PROVIDER_CUSTOM_S3 => [
                'endpoint' => ['nullable', 'required_if:provider,cloudflare_r2,custom_s3', 'url', 'max:2048'],
                'bucket' => ['required', 'string', 'max:255'],
                'access_key_id' => [$secretsRequired ? 'required_without:secrets.access_key_id' : 'nullable', 'string'],
                'secret_access_key' => [$secretsRequired ? 'required_without:secrets.secret_access_key' : 'nullable', 'string'],
                'secrets.access_key_id' => ['nullable', 'string'],
                'secrets.secret_access_key' => ['nullable', 'string'],
            ],
            BackupDestination::PROVIDER_WEBDAV => [
                'settings.url' => ['required', 'url', 'max:2048'],
                'settings.path' => ['nullable', 'string', 'max:2048'],
                'settings.insecure' => ['boolean'],
                'secrets.username' => ['nullable', 'string'],
                'secrets.password' => ['nullable', 'string'],
            ],
            BackupDestination::PROVIDER_SSH => [
                'settings.host' => ['required', 'string', 'max:255'],
                'settings.port' => ['nullable', 'integer', 'min:1', 'max:65535'],
                'settings.remote_path' => ['required', 'string', 'max:2048'],
                'settings.identity_file' => ['nullable', 'string', 'max:2048'],
                'settings.host_key' => ['nullable', 'string', 'max:4096'],
                'secrets.user' => [$secretsRequired ? 'required' : 'nullable', 'string'],
                'secrets.password' => $secretsRequired
                    ? ['nullable', 'required_without:secrets.private_key', 'string']
                    : ['nullable', 'string'],
                'secrets.private_key' => $secretsRequired
                    ? ['nullable', 'required_without:secrets.password', 'string']
                    : ['nullable', 'string'],
                'secrets.private_key_passphrase' => ['nullable', 'string'],
            ],
            BackupDestination::PROVIDER_AZURE_BLOB => [
                'settings.account_name' => $secretsRequired
                    ? ['nullable', 'required_without:secrets.connection_string', 'string', 'max:255']
                    : ['nullable', 'string', 'max:255'],
                'settings.container' => ['required', 'string', 'max:255'],
                'settings.endpoint' => ['nullable', 'url', 'max:2048'],
                'settings.access_tier' => ['nullable', 'string', 'max:255'],
                'secrets.account_key' => $secretsRequired
                    ? ['nullable', 'required_without:secrets.connection_string', 'string']
                    : ['nullable', 'string'],
                'secrets.connection_string' => ['nullable', 'string'],
            ],
            BackupDestination::PROVIDER_DROPBOX => [
                'settings.remote_path' => ['nullable', 'string', 'max:2048'],
                'settings.concurrency_level' => ['nullable', 'integer', 'min:1', 'max:32'],
                'secrets.app_key' => [$secretsRequired ? 'required' : 'nullable', 'string'],
                'secrets.app_secret' => [$secretsRequired ? 'required' : 'nullable', 'string'],
                'secrets.refresh_token' => [$secretsRequired ? 'required' : 'nullable', 'string'],
            ],
            BackupDestination::PROVIDER_GOOGLE_DRIVE => [
                'settings.folder_id' => ['required', 'string', 'max:255'],
                'settings.impersonate_subject' => ['nullable', 'email', 'max:255'],
                'settings.endpoint' => ['nullable', 'url', 'max:2048'],
                'settings.token_url' => ['nullable', 'url', 'max:2048'],
                'secrets.credentials_json' => [$secretsRequired ? 'required' : 'nullable', 'json'],
            ],
            BackupDestination::PROVIDER_LOCAL => [
                'settings.archive_path' => ['required', 'string', 'max:2048'],
                'settings.archive_mount_source' => ['nullable', 'string', 'max:2048'],
            ],
            BackupDestination::PROVIDER_DOCKER_VOLUME => [
                'settings.volume_name' => ['required', 'string', 'max:255'],
                'settings.path_prefix' => ['nullable', 'string', 'max:255'],
            ],
            default => [],
        };
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateStorageLimits($validator);
            $this->validateLocalDestinationPaths($validator);
            $this->validateDockerVolumeSettings($validator);
        });
    }

    /**
     * The Docker volume provider mounts `<volume_name>:/archive` into the Offen
     * container and into throwaway helper containers. Hold the name to Docker's
     * named-volume grammar and the optional sub-path to a safe relative path, so
     * a `/` cannot turn the source into a host bind mount, a `:` cannot inject
     * extra mount options, and a `..` cannot escape the archive directory.
     */
    protected function validateDockerVolumeSettings(Validator $validator): void
    {
        if ($this->input('provider') !== BackupDestination::PROVIDER_DOCKER_VOLUME) {
            return;
        }

        $volumeName = (string) $this->input('settings.volume_name', '');

        // The `required` rule already reports an empty name; only flag a
        // non-empty name that fails Docker's named-volume grammar.
        if ($volumeName !== '' && ! DockerVolumeName::isValidName($volumeName)) {
            $validator->errors()->add(
                'settings.volume_name',
                'The Docker volume name may only contain letters, numbers, and the characters _ . - (it cannot contain slashes or colons).'
            );
        }

        $pathPrefix = (string) $this->input('settings.path_prefix', '');

        if ($pathPrefix !== '' && ! DockerVolumeName::isValidSubpath($pathPrefix)) {
            $validator->errors()->add(
                'settings.path_prefix',
                'The sub-path must be a relative path and cannot contain "..", a colon, or a leading slash.'
            );
        }
    }

    /**
     * The local provider bind-mounts these paths on the Docker host
     * read-write. Hold them to the same fail-closed host-path allowlist as a
     * host-path backup source, and reject characters (`:`) that could inject
     * extra mount options into the `src:dst` spec.
     */
    protected function validateLocalDestinationPaths(Validator $validator): void
    {
        if ($this->input('provider') !== BackupDestination::PROVIDER_LOCAL) {
            return;
        }

        $policy = app(HostPathPolicy::class);

        foreach (['settings.archive_path', 'settings.archive_mount_source'] as $field) {
            $value = (string) $this->input($field, '');

            // archive_mount_source is optional; the `required` rule on
            // archive_path already reports an empty required path.
            if ($value === '') {
                continue;
            }

            if (str_contains($value, ':')) {
                $validator->errors()->add($field, 'Local archive paths cannot contain a colon.');

                continue;
            }

            if ($message = $policy->validationError($policy->normalize($value))) {
                $validator->errors()->add($field, $message);
            }
        }
    }

    protected function validateStorageLimits(Validator $validator): void
    {
        $warning = $this->input('settings.storage_limit_warning_bytes');
        $critical = $this->input('settings.storage_limit_critical_bytes');

        if ($warning !== null && $warning !== '' && $critical !== null && $critical !== '' && (int) $warning > (int) $critical) {
            $validator->errors()->add('settings.storage_limit_critical_bytes', 'The critical storage limit must be greater than or equal to the warning storage limit.');
        }
    }
}
