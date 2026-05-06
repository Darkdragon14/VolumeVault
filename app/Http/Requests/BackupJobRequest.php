<?php

namespace App\Http\Requests;

use App\Models\BackupJob;
use App\Services\Scheduling\BackupScheduleCalculator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class BackupJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'volume_name' => ['required', 'string', 'max:255'],
            'backup_destination_id' => ['required', 'integer', 'exists:backup_destinations,id'],
            'schedule_type' => ['required', 'string', Rule::in([
                BackupJob::SCHEDULE_HOURLY,
                BackupJob::SCHEDULE_DAILY,
                BackupJob::SCHEDULE_WEEKLY,
                BackupJob::SCHEDULE_CRON,
            ])],
            'schedule_config' => ['nullable', 'array'],
            'retention_days' => ['nullable', 'integer', 'min:1'],
            'retention_count' => ['nullable', 'integer', 'min:1'],
            'backup_exclude_regexp' => ['nullable', 'string', 'max:1000'],
            'stop_containers_before_backup' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            try {
                app(BackupScheduleCalculator::class)->normalize($this->input('schedule_type'), $this->input('schedule_config', []));
            } catch (InvalidArgumentException $exception) {
                $validator->errors()->add('schedule_config', $exception->getMessage());
            }
        });
    }

    public function normalizedScheduleConfig(): array
    {
        return app(BackupScheduleCalculator::class)->normalize($this->input('schedule_type'), $this->input('schedule_config', []));
    }
}
