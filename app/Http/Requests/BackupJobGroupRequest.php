<?php

namespace App\Http\Requests;

use App\Models\BackupJobGroup;
use App\Services\Scheduling\BackupScheduleCalculator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * Validates a backup group's own settings: the schedule, notifications and
 * failure policy it owns on behalf of its member jobs. Members (their sources
 * and destinations) are managed as ordinary backup jobs, not here.
 */
class BackupJobGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'schedule_type' => ['required', 'string', Rule::in([
                BackupJobGroup::SCHEDULE_HOURLY,
                BackupJobGroup::SCHEDULE_DAILY,
                BackupJobGroup::SCHEDULE_WEEKLY,
                BackupJobGroup::SCHEDULE_CRON,
            ])],
            'schedule_config' => ['nullable', 'array'],
            'timezone' => ['nullable', 'string', Rule::in(\DateTimeZone::listIdentifiers())],
            'failure_policy' => ['required', Rule::in([
                BackupJobGroup::FAILURE_POLICY_CONTINUE,
                BackupJobGroup::FAILURE_POLICY_STOP,
            ])],
            'notifications_enabled' => ['boolean'],
            'notification_channel_ids' => ['nullable', 'array'],
            'notification_channel_ids.*' => ['integer', 'distinct', 'exists:notification_channels,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            try {
                app(BackupScheduleCalculator::class)->normalize(
                    (string) $this->input('schedule_type'),
                    (array) $this->input('schedule_config'),
                );
            } catch (InvalidArgumentException $exception) {
                $validator->errors()->add('schedule_config', $exception->getMessage());
            }
        });
    }

    public function normalizedScheduleConfig(): array
    {
        // Coerce so a null/missing/scalar schedule input surfaces as a validation
        // error (via normalize's InvalidArgumentException) rather than a TypeError.
        return app(BackupScheduleCalculator::class)->normalize(
            (string) $this->input('schedule_type'),
            (array) $this->input('schedule_config'),
        );
    }
}
