<?php

namespace App\Services\Agents;

use App\Http\Requests\Concerns\ValidatesBackupDestination;
use App\Models\BackupDestination;
use App\Services\BackupSources\HostPathPolicy;
use App\Services\Docker\DockerVolumeName;
use App\Services\Security\OutboundHostGuard;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class AgentOperationSpecification
{
    use ValidatesBackupDestination;

    private array $destination = [];

    public function input(string $key, mixed $default = null): mixed
    {
        return data_get($this->destination, $key, $default);
    }

    /** Structural validation only: acceptance/replay must never depend on local policy or DNS. */
    public function validate(array $operation): void
    {
        try {
            app(AgentOperationStore::class)->assertId($operation['id'] ?? '');
            $rules = [
                'id' => ['required', 'uuid'],
                'token' => ['required', 'regex:/\A[0-9a-f]{64}\z/'],
                'kind' => ['required', 'in:backup,restore'],
                'spec' => ['required', 'array:version,job,destination,safety_destination,run,relay'],
                'spec.relay' => ['sometimes', 'array:id,size_bytes,sha256'],
                'spec.relay.id' => ['required_with:spec.relay', 'uuid'],
                'spec.relay.size_bytes' => ['required_with:spec.relay', 'integer:strict', 'min:1'],
                'spec.relay.sha256' => ['required_with:spec.relay', 'regex:/\A[0-9a-f]{64}\z/'],
                'spec.version' => ['required', 'integer', 'in:1'],
                'spec.job' => ['required', 'array:name,source_type,volume_name,host_path,retention_days,retention_count,backup_filter_mode,backup_exclude_regexp,backup_include_paths,stop_containers_before_backup,stop_container_names,timezone'],
                'spec.job.name' => ['required', 'string', 'max:255'],
                'spec.job.source_type' => ['required', 'in:docker_volume,host_path'],
                'spec.job.volume_name' => ['nullable', 'string', 'max:255'],
                'spec.job.host_path' => ['nullable', 'string', 'max:4096'],
                'spec.job.retention_days' => ['nullable', 'integer', 'min:0'],
                'spec.job.retention_count' => ['nullable', 'integer', 'min:0'],
                'spec.job.backup_filter_mode' => ['nullable', 'in:include,exclude'],
                'spec.job.backup_exclude_regexp' => ['nullable', 'string', 'max:16384'],
                'spec.job.backup_include_paths' => ['nullable', 'string', 'max:16384'],
                'spec.job.stop_containers_before_backup' => ['sometimes', 'boolean:strict'],
                'spec.job.stop_container_names' => ['nullable', 'array', 'max:1000'],
                'spec.job.stop_container_names.*' => ['string', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9_.-]*\z/'],
                'spec.job.timezone' => ['nullable', 'timezone'],
                'spec.destination' => ['required', 'array:name,provider,endpoint,region,bucket,path_prefix,access_key_id,secret_access_key,use_path_style_endpoint,settings,secrets'],
                'spec.safety_destination' => ['sometimes', 'array:name,provider,endpoint,region,bucket,path_prefix,access_key_id,secret_access_key,use_path_style_endpoint,settings,secrets'],
                'spec.run' => ['present', 'array:backup_filename,selected_backup_key,source_volume_name,target_volume_name,mode,backup_before_overwrite,confirmation_text'],
                'spec.run.backup_filename' => ['nullable', 'string', 'max:255', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9_.-]*\z/'],
                'spec.run.selected_backup_key' => ['nullable', 'string', 'max:4096'],
                'spec.run.source_volume_name' => ['nullable', 'string', 'max:4096'],
                'spec.run.target_volume_name' => ['nullable', 'string', 'max:255'],
                'spec.run.mode' => ['nullable', 'in:new_volume,inplace,safe_inplace'],
                'spec.run.backup_before_overwrite' => ['sometimes', 'boolean:strict'],
                'spec.run.confirmation_text' => ['nullable', 'string', 'max:255'],
            ];
            if (($operation['kind'] ?? null) === 'destination') {
                $rules = array_filter($rules, fn (string $key): bool => ! str_starts_with($key, 'spec.job') && ! str_starts_with($key, 'spec.run') && $key !== 'spec.safety_destination', ARRAY_FILTER_USE_KEY);
                $rules['kind'] = ['required', 'in:destination'];
                $rules['spec'] = ['required', 'array:version,destination,action,cursor,limit,selected_backup'];
                $rules['spec.action'] = ['required', 'in:test,list,stats'];
                $rules['spec.cursor'] = ['nullable', 'string', 'max:16384', 'prohibited_unless:spec.action,list'];
                if (isset($operation['spec']['selected_backup'])) {
                    $rules['spec.cursor'][] = 'prohibited';
                }
                $rules['spec.limit'] = ['required', 'integer:strict', 'min:1', 'max:1000'];
                $rules['spec.selected_backup'] = ['sometimes', 'array:key,display_name,size,last_modified', 'prohibited_unless:spec.action,list'];
                $rules['spec.selected_backup.key'] = ['required_with:spec.selected_backup', 'string', 'max:4096'];
                $rules['spec.selected_backup.display_name'] = ['required_with:spec.selected_backup', 'string', 'max:4096'];
                $rules['spec.selected_backup.size'] = ['nullable', 'integer:strict', 'min:0'];
                $rules['spec.selected_backup.last_modified'] = ['nullable', 'string', 'max:64', 'date'];
            }
            if (($operation['kind'] ?? null) === 'archive_export') {
                $rules = array_filter($rules, fn (string $key): bool => ! str_starts_with($key, 'spec.job') && ! str_starts_with($key, 'spec.run') && ! str_starts_with($key, 'spec.relay') && $key !== 'spec.safety_destination', ARRAY_FILTER_USE_KEY);
                $rules['kind'] = ['required', 'in:archive_export'];
                $rules['spec'] = ['required', 'array:version,destination,relay'];
                $rules['spec.relay'] = ['required', 'array:id,key,max_bytes'];
                $rules['spec.relay.id'] = ['required', 'uuid'];
                $rules['spec.relay.key'] = ['required', 'string', 'max:4096'];
                $rules['spec.relay.max_bytes'] = ['required', 'integer:strict', 'min:1'];
            }
            if (array_diff(array_keys($operation), ['id', 'token', 'kind', 'spec']) !== [] || ($operation['spec']['version'] ?? null) !== 1) {
                throw new RuntimeException;
            }
            Validator::make($operation, $rules)->validate();
            foreach ($this->destinations($operation) as $destination) {
                $this->destination = $destination;
                $this->assertConsistentS3Endpoint();
                $providerRules = $this->providerRules(true);
                $destinationRules = [
                    'name' => ['required', 'string', 'max:255'],
                    'provider' => ['required', 'in:'.implode(',', BackupDestination::PROVIDERS)],
                    'endpoint' => ['nullable', 'string', 'max:2048'],
                    'region' => ['nullable', 'string', 'max:255'],
                    'bucket' => ['nullable', 'string', 'max:255'],
                    'path_prefix' => ['nullable', 'string', 'max:2048'],
                    'access_key_id' => ['nullable', 'string', 'max:65536'],
                    'secret_access_key' => ['nullable', 'string', 'max:65536'],
                    'use_path_style_endpoint' => ['sometimes', 'boolean:strict'],
                    'settings' => ['nullable', 'array'],
                    'secrets' => ['nullable', 'array'],
                ];
                $allowedSettings = ['storage_limit_warning_bytes', 'storage_limit_critical_bytes'];
                foreach ($allowedSettings as $field) {
                    $providerRules['settings.'.$field] = ['nullable', 'integer', 'min:0'];
                }
                $allowedSecrets = BackupDestination::SECRET_FIELDS[$this->input('provider')] ?? [];
                foreach (array_keys($providerRules) as $field) {
                    if (str_starts_with($field, 'settings.')) {
                        $allowedSettings[] = substr($field, 9);
                    }
                }
                if (in_array($this->input('provider'), BackupDestination::S3_PROVIDERS, true)) {
                    foreach (['endpoint', 'region', 'bucket', 'path_prefix', 'use_path_style_endpoint'] as $field) {
                        $allowedSettings[] = $field;
                        $providerRules['settings.'.$field] = $destinationRules[$field];
                    }
                }
                if (array_diff(array_keys($this->input('settings', []) ?? []), $allowedSettings) !== []
                    || array_diff(array_keys($this->input('secrets', []) ?? []), $allowedSecrets) !== []) {
                    throw new RuntimeException;
                }
                $validator = Validator::make($this->destination, [...$destinationRules, ...$providerRules]);
                $validator->after(function ($validator): void {
                    $this->validateDockerVolumeSettings($validator);
                });
                $validator->validate();
            }
            if ($operation['kind'] === 'archive_export') {
                if (! in_array($operation['spec']['destination']['provider'], ['local', 'docker_volume'], true)) {
                    throw new RuntimeException;
                }
                DockerVolumeName::assertKey($operation['spec']['relay']['key']);
            } elseif ($operation['kind'] !== 'destination') {
                $this->validateSourceAndTarget($operation);
            }
            if (isset($operation['spec']['relay']) && $operation['kind'] !== 'archive_export'
                && ($operation['kind'] !== 'restore' || ($operation['spec']['run']['mode'] ?? '') !== 'new_volume' || ($operation['spec']['run']['backup_before_overwrite'] ?? false))) {
                throw new RuntimeException;
            }
        } catch (\Throwable) {
            // Validation exceptions can contain credential-bearing URLs/values.
            throw new RuntimeException('Agent operation specification is invalid.');
        }
    }

    /** Local policy is checked only after durable acceptance, before execution. */
    public function validateLocalPolicy(array $operation): void
    {
        try {
            if ($operation['kind'] === 'restore' && isset($operation['spec']['relay'])) {
                if ($operation['spec']['relay']['size_bytes'] > (int) config('volumevault.archive_relay.max_bytes')) {
                    throw new RuntimeException;
                }

                return;
            }
            if ($operation['kind'] === 'backup' && $operation['spec']['job']['source_type'] === 'host_path') {
                app(HostPathPolicy::class)->assertValid($operation['spec']['job']['host_path'] ?? '');
            }
            foreach ($this->destinations($operation) as $destination) {
                $this->destination = $destination;
                if (filled($this->input('settings.identity_file'))) {
                    throw new RuntimeException;
                }
                $validator = Validator::make($destination, []);
                $validator->after(fn ($validator) => $this->validateLocalDestinationPaths($validator));
                $validator->validate();
                $this->guardDestination();
            }
        } catch (\Throwable) {
            throw new RuntimeException('Agent operation is blocked by local policy.');
        }
    }

    protected function destinationHostId(): int
    {
        return 1;
    }

    private function destinations(array $operation): array
    {
        $destinations = [$operation['spec']['destination']];
        if (isset($operation['spec']['safety_destination'])) {
            $destinations[] = $operation['spec']['safety_destination'];
        }

        return $destinations;
    }

    private function validateSourceAndTarget(array $operation): void
    {
        $job = $operation['spec']['job'];
        $run = $operation['spec']['run'];
        if ($job['source_type'] === 'docker_volume') {
            $this->volume($job['volume_name'] ?? '');
        }
        if ($operation['kind'] === 'backup') {
            if (empty($run['backup_filename'])) {
                throw new RuntimeException;
            }

            return;
        }
        $this->volume($run['target_volume_name'] ?? '');
        if (empty($run['selected_backup_key']) || empty($run['source_volume_name'])) {
            throw new RuntimeException;
        }
        if (($run['mode'] ?? 'new_volume') !== 'new_volume') {
            if ($job['source_type'] !== 'docker_volume' || ($run['confirmation_text'] ?? '') !== $run['target_volume_name']) {
                throw new RuntimeException;
            }
        }
        foreach (['selected_backup_key', 'source_volume_name'] as $field) {
            if (preg_match('/[\x00-\x1f]/', $run[$field])) {
                throw new RuntimeException;
            }
        }
    }

    private function volume(string $name): void
    {
        if (! DockerVolumeName::isValidName($name)) {
            throw new RuntimeException;
        }
    }

    /** Apply local SSRF policy to uploads as well as downloads. */
    private function guardDestination(): void
    {
        $guard = app(OutboundHostGuard::class);
        $s3 = in_array($this->input('provider'), BackupDestination::S3_PROVIDERS, true);
        $this->assertConsistentS3Endpoint();
        $fields = match ($this->input('provider')) {
            'aws_s3', 'cloudflare_r2', 'custom_s3', 'azure_blob' => ['endpoint'],
            'webdav' => ['url'],
            'google_drive' => ['endpoint', 'token_url'],
            default => [],
        };
        foreach ($fields as $field) {
            // Offen uploads use the primary S3 endpoint; nested copies must
            // agree so SDK downloads and policy validation cannot diverge.
            $url = $s3 ? $this->input($field) : $this->input('settings.'.$field, $this->input($field));
            if ($url !== null && $url !== '') {
                if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
                    throw new RuntimeException;
                }
                if (array_intersect(array_keys(parse_url($url)), ['user', 'pass', 'query', 'fragment']) !== []) {
                    throw new RuntimeException;
                }
                $guard->assertUrlAllowed($url);
            }
        }
        if ($this->input('provider') === 'ssh') {
            $host = (string) $this->input('settings.host');
            if (preg_match('/[\s\/@\\\\]/', $host)) {
                throw new RuntimeException;
            }
            $guard->assertHostAllowed($host);
        }
        if ($this->input('provider') === 'azure_blob') {
            $connection = (string) $this->input('secrets.connection_string');
            $parts = [];
            foreach (explode(';', $connection) as $part) {
                [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
                $parts[strtolower($name)] = $value;
                if (str_ends_with(strtolower($name), 'endpoint')) {
                    $guard->assertUrlAllowed($value);
                }
            }
            if (isset($parts['endpointsuffix'])) {
                $guard->assertHostAllowed(($parts['accountname'] ?? $this->input('settings.account_name')).'.blob.'.$parts['endpointsuffix']);
            }
            if (strtolower($parts['usedevelopmentstorage'] ?? '') === 'true') {
                $guard->assertHostAllowed('127.0.0.1');
            }
            if (isset($parts['developmentstorageproxyuri'])) {
                $guard->assertUrlAllowed($parts['developmentstorageproxyuri']);
            }
        }
        if ($this->input('provider') === 'google_drive') {
            $credentials = json_decode((string) $this->input('secrets.credentials_json'), true, flags: JSON_THROW_ON_ERROR);
            if (! in_array($credentials['type'] ?? '', ['service_account', 'authorized_user'], true)) {
                throw new RuntimeException;
            }
            if (isset($credentials['token_uri'])) {
                $guard->assertUrlAllowed($credentials['token_uri']);
            }
        }
    }

    private function assertConsistentS3Endpoint(): void
    {
        if (! in_array($this->input('provider'), BackupDestination::S3_PROVIDERS, true)) {
            return;
        }
        $settings = $this->input('settings', []) ?? [];
        if (! is_array($settings)
            || (array_key_exists('endpoint', $settings) && $settings['endpoint'] !== $this->input('endpoint'))) {
            throw new RuntimeException('Conflicting S3 endpoint representations.');
        }
    }
}
