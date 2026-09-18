<?php

namespace App\Http\Requests;

use App\Actions\Backup\RenderBackupFilename;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Services\Docker\LocalDockerExecution;
use App\Services\Scheduling\BackupScheduleCalculator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class UpdateDockerLabelBackupSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        LocalDockerExecution::validate();

        return [
            'enabled' => ['required', 'boolean'],
            'backup_destination_id' => ['nullable', 'integer', 'exists:backup_destinations,id'],
            'schedule_type' => ['required', Rule::in([
                BackupJob::SCHEDULE_HOURLY,
                BackupJob::SCHEDULE_DAILY,
                BackupJob::SCHEDULE_WEEKLY,
                BackupJob::SCHEDULE_CRON,
            ])],
            'schedule_config' => ['required', 'array'],
            'timezone' => ['nullable', 'string', Rule::in(\DateTimeZone::listIdentifiers())],
            'retention_days' => ['nullable', 'integer', 'min:1'],
            'retention_count' => ['nullable', 'integer', 'min:1'],
            'backup_filter_mode' => ['required', Rule::in([BackupJob::FILTER_MODE_EXCLUDE, BackupJob::FILTER_MODE_INCLUDE])],
            'backup_include_paths' => ['nullable', 'string', 'max:2000'],
            'backup_exclude_regexp' => ['nullable', 'string', 'max:1000'],
            'backup_filename_template' => ['nullable', 'string', 'max:180'],
            'notifications_enabled' => ['required', 'boolean'],
            'notification_channel_ids' => ['nullable', 'array'],
            'notification_channel_ids.*' => ['integer', 'distinct', 'exists:notification_channels,id'],
            'alert_notifications_enabled' => ['required', 'boolean'],
            'stop_containers_before_backup' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->boolean('enabled') && ! $this->filled('backup_destination_id')) {
                $validator->errors()->add('backup_destination_id', 'Choose a default destination before enabling Docker label backups.');
            }

            if ($this->boolean('enabled') && $this->filled('backup_destination_id') && ! BackupDestination::query()
                ->whereKey($this->integer('backup_destination_id'))
                ->where('is_active', true)
                ->exists()) {
                $validator->errors()->add('backup_destination_id', 'The default destination must be active.');
            }

            try {
                app(BackupScheduleCalculator::class)->normalize((string) $this->input('schedule_type'), (array) $this->input('schedule_config'));
            } catch (InvalidArgumentException $exception) {
                $validator->errors()->add('schedule_config', $exception->getMessage());
            }

            if ($message = app(RenderBackupFilename::class)->validationError($this->input('backup_filename_template'))) {
                $validator->errors()->add('backup_filename_template', $message);
            }

            foreach (preg_split('/\s*,\s*/', (string) $this->input('backup_include_paths'), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $path) {
                $path = trim($path, '/');
                $segments = explode('/', $path);

                if ($path === '' || mb_strlen($path) > 200 || in_array('.', $segments, true) || in_array('..', $segments, true)) {
                    $validator->errors()->add('backup_include_paths', 'Include paths must be relative, 200 characters or fewer, and cannot contain "." or ".." segments.');

                    break;
                }
            }
        });
    }

    public function defaults(): array
    {
        $validated = $this->validated();

        return collect($validated)->except(['enabled', 'backup_destination_id'])->all();
    }
}
