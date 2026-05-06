<?php

namespace App\Http\Requests;

use App\Models\BackupDestination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDestinationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge($this->baseRules(), $this->providerRules(true));
    }

    private function baseRules(): array
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
        ];
    }

    private function providerRules(bool $secretsRequired): array
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
                'secrets.user' => ['required', 'string'],
                'secrets.password' => ['nullable', 'required_without:secrets.private_key', 'string'],
                'secrets.private_key' => ['nullable', 'required_without:secrets.password', 'string'],
                'secrets.private_key_passphrase' => ['nullable', 'string'],
            ],
            BackupDestination::PROVIDER_AZURE_BLOB => [
                'settings.account_name' => ['nullable', 'required_without:secrets.connection_string', 'string', 'max:255'],
                'settings.container' => ['required', 'string', 'max:255'],
                'settings.endpoint' => ['nullable', 'url', 'max:2048'],
                'settings.access_tier' => ['nullable', 'string', 'max:255'],
                'secrets.account_key' => ['nullable', 'required_without:secrets.connection_string', 'string'],
                'secrets.connection_string' => ['nullable', 'string'],
            ],
            BackupDestination::PROVIDER_DROPBOX => [
                'settings.remote_path' => ['nullable', 'string', 'max:2048'],
                'settings.concurrency_level' => ['nullable', 'integer', 'min:1', 'max:32'],
                'secrets.app_key' => ['required', 'string'],
                'secrets.app_secret' => ['required', 'string'],
                'secrets.refresh_token' => ['required', 'string'],
            ],
            BackupDestination::PROVIDER_GOOGLE_DRIVE => [
                'settings.folder_id' => ['required', 'string', 'max:255'],
                'settings.impersonate_subject' => ['nullable', 'email', 'max:255'],
                'settings.endpoint' => ['nullable', 'url', 'max:2048'],
                'settings.token_url' => ['nullable', 'url', 'max:2048'],
                'secrets.credentials_json' => ['required', 'json'],
            ],
            BackupDestination::PROVIDER_LOCAL => [
                'settings.archive_path' => ['required', 'string', 'max:2048'],
                'settings.archive_mount_source' => ['nullable', 'string', 'max:2048'],
            ],
            default => [],
        };
    }
}
