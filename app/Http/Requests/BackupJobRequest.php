<?php

namespace App\Http\Requests;

use App\Actions\Backup\RenderBackupFilename;
use App\Actions\Docker\ValidateHostPathMount;
use App\Http\Requests\Concerns\ValidatesBackupSizeRange;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Services\BackupSources\HostPathPolicy;
use App\Services\Scheduling\BackupScheduleCalculator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;
use Throwable;

class BackupJobRequest extends FormRequest
{
    use ValidatesBackupSizeRange;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $sourceType = (string) ($this->input('source_type') ?: BackupJob::SOURCE_TYPE_DOCKER_VOLUME);
        $hostPath = app(HostPathPolicy::class)->normalize($this->input('host_path'));
        $alertConfigs = $this->customAlertSettingsEnabled() ? $this->input('alert_configs') : null;
        $backupFilenameTemplate = trim((string) $this->input('backup_filename_template', ''));

        $this->merge([
            'source_type' => $sourceType,
            'host_path' => $hostPath !== '' ? $hostPath : null,
            'volume_name' => $sourceType === BackupJob::SOURCE_TYPE_HOST_PATH ? null : $this->input('volume_name'),
            'backup_filename_template' => $backupFilenameTemplate !== '' ? $backupFilenameTemplate : null,
            'alert_configs' => $alertConfigs,
            // Absent/blank planning_mode = a standalone job (the historical
            // behaviour), so existing clients keep working. A *present* value is
            // passed through unchanged so an invalid one (e.g. a typo) is rejected by
            // the enum rule rather than silently coerced to standalone.
            'planning_mode' => $this->filled('planning_mode') ? $this->input('planning_mode') : 'standalone',
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'source_type' => ['required', 'string', Rule::in([
                BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
                BackupJob::SOURCE_TYPE_HOST_PATH,
            ])],
            'volume_name' => ['required_if:source_type,'.BackupJob::SOURCE_TYPE_DOCKER_VOLUME, 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'host_path' => ['required_if:source_type,'.BackupJob::SOURCE_TYPE_HOST_PATH, 'nullable', 'string', 'max:255'],
            'backup_destination_id' => ['required', 'integer', 'exists:backup_destinations,id'],
            // Planning mode: a standalone job keeps its own schedule (below); a
            // grouped job delegates the schedule and notifications to its group.
            'planning_mode' => ['nullable', Rule::in(['standalone', 'group'])],
            'group_selection' => ['nullable', Rule::in(['existing', 'new'])],
            'backup_job_group_id' => [Rule::requiredIf(fn (): bool => $this->isExistingGroupMode()), 'nullable', 'integer', 'exists:backup_job_groups,id'],
            'new_group' => ['nullable', 'array'],
            'new_group.name' => [Rule::requiredIf(fn (): bool => $this->isNewGroupMode()), 'nullable', 'string', 'max:255'],
            'new_group.schedule_type' => [Rule::requiredIf(fn (): bool => $this->isNewGroupMode()), 'nullable', 'string', Rule::in([
                BackupJobGroup::SCHEDULE_HOURLY,
                BackupJobGroup::SCHEDULE_DAILY,
                BackupJobGroup::SCHEDULE_WEEKLY,
                BackupJobGroup::SCHEDULE_CRON,
            ])],
            'new_group.schedule_config' => ['nullable', 'array'],
            'new_group.timezone' => ['nullable', 'string', Rule::in(\DateTimeZone::listIdentifiers())],
            'new_group.failure_policy' => [Rule::requiredIf(fn (): bool => $this->isNewGroupMode()), 'nullable', Rule::in([
                BackupJobGroup::FAILURE_POLICY_CONTINUE,
                BackupJobGroup::FAILURE_POLICY_STOP,
            ])],
            'new_group.notifications_enabled' => ['nullable', 'boolean'],
            'new_group.notification_channel_ids' => ['nullable', 'array'],
            'new_group.notification_channel_ids.*' => ['integer', 'distinct', 'exists:notification_channels,id'],
            // Required for a standalone job; ignored (delegated to the group) when grouped.
            'schedule_type' => [Rule::requiredIf(fn (): bool => ! $this->isGroupMode()), 'nullable', 'string', Rule::in([
                BackupJob::SCHEDULE_HOURLY,
                BackupJob::SCHEDULE_DAILY,
                BackupJob::SCHEDULE_WEEKLY,
                BackupJob::SCHEDULE_CRON,
            ])],
            'schedule_config' => ['nullable', 'array'],
            'timezone' => ['nullable', 'string', Rule::in(\DateTimeZone::listIdentifiers())],
            'retention_days' => ['nullable', 'integer', 'min:1'],
            'retention_count' => ['nullable', 'integer', 'min:1'],
            'backup_exclude_regexp' => ['nullable', 'string', 'max:1000'],
            'backup_filename_template' => ['nullable', 'string', 'max:180'],
            'notifications_enabled' => ['boolean'],
            'notification_channel_ids' => ['nullable', 'array'],
            'notification_channel_ids.*' => ['integer', 'distinct', 'exists:notification_channels,id'],
            'use_custom_alert_settings' => ['boolean'],
            'alert_notifications_enabled' => ['boolean'],
            'alert_configs' => ['nullable', 'array'],
            'alert_configs.*.alert_rule_id' => ['required_with:alert_configs', 'integer', 'distinct', 'exists:alert_rules,id'],
            'alert_configs.*.enabled' => ['nullable', 'boolean'],
            'alert_configs.*.config' => ['nullable', 'array'],
            'alert_configs.*.config.cooldown_minutes' => ['nullable', 'integer', 'min:0'],
            'alert_configs.*.config.reminder_enabled' => ['nullable', 'boolean'],
            'alert_configs.*.config.backup_too_old_days' => ['nullable', 'integer', 'min:1'],
            'alert_configs.*.config.job_never_succeeded_min_runs' => ['nullable', 'integer', 'min:1'],
            'alert_configs.*.config.job_in_error_days' => ['nullable', 'integer', 'min:1'],
            'alert_configs.*.config.backup_size_out_of_range_min_bytes' => ['nullable', 'integer', 'min:0'],
            'alert_configs.*.config.backup_size_out_of_range_max_bytes' => ['nullable', 'integer', 'min:1'],
            'stop_containers_before_backup' => ['boolean'],
            'stop_container_names' => ['nullable', 'array'],
            'stop_container_names.*' => ['string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // A grouped job has no schedule of its own — the group owns it.
            if (! $this->isGroupMode()) {
                try {
                    app(BackupScheduleCalculator::class)->normalize((string) $this->input('schedule_type'), (array) $this->input('schedule_config'));
                } catch (InvalidArgumentException $exception) {
                    $validator->errors()->add('schedule_config', $exception->getMessage());
                }
            }

            if ($this->isNewGroupMode()) {
                try {
                    app(BackupScheduleCalculator::class)->normalize((string) $this->input('new_group.schedule_type'), (array) $this->input('new_group.schedule_config'));
                } catch (InvalidArgumentException $exception) {
                    $validator->errors()->add('new_group.schedule_config', $exception->getMessage());
                }
            }

            $this->validateHostPathSource($validator);
            $this->validateBackupFilenameTemplate($validator);
            $this->validateAlertSizeRanges($validator);
        });
    }

    public function isGroupMode(): bool
    {
        return $this->input('planning_mode') === 'group';
    }

    public function isNewGroupMode(): bool
    {
        return $this->isGroupMode() && $this->input('group_selection') === 'new';
    }

    public function isExistingGroupMode(): bool
    {
        return $this->isGroupMode() && $this->input('group_selection') !== 'new';
    }

    public function normalizedScheduleConfig(): array
    {
        return app(BackupScheduleCalculator::class)->normalize((string) $this->input('schedule_type'), (array) $this->input('schedule_config'));
    }

    private function validateHostPathSource(Validator $validator): void
    {
        if ($this->input('source_type') !== BackupJob::SOURCE_TYPE_HOST_PATH) {
            return;
        }

        $hostPath = (string) $this->input('host_path', '');

        if ($hostPath === '') {
            return;
        }

        $policy = app(HostPathPolicy::class);

        if ($message = $policy->validationError($hostPath)) {
            $validator->errors()->add('host_path', $message);
        }

        if ($validator->errors()->has('host_path')) {
            return;
        }

        try {
            app(ValidateHostPathMount::class)->handle($hostPath);
        } catch (Throwable $exception) {
            $validator->errors()->add('host_path', str($exception->getMessage() ?: 'Host path could not be mounted by Docker.')->limit(500)->toString());
        }
    }

    private function customAlertSettingsEnabled(): bool
    {
        if ($this->has('use_custom_alert_settings')) {
            return $this->boolean('use_custom_alert_settings');
        }

        $job = $this->route('backup_job');

        return $job instanceof BackupJob && $job->use_custom_alert_settings;
    }

    private function validateAlertSizeRanges(Validator $validator): void
    {
        $this->validateBackupSizeRanges(
            $validator,
            $this->input('alert_configs', []) ?? [],
            fn (int $index): string => 'alert_configs.'.$index.'.config.backup_size_out_of_range_max_bytes',
        );
    }

    private function validateBackupFilenameTemplate(Validator $validator): void
    {
        if ($message = app(RenderBackupFilename::class)->validationError($this->input('backup_filename_template'))) {
            $validator->errors()->add('backup_filename_template', $message);
        }
    }
}
