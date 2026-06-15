<?php

namespace App\Http\Requests;

use App\Models\BackupJob;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StackBackupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'stack' => ['nullable', 'string', 'max:255'],
            'backup_destination_id' => ['nullable', 'integer', 'exists:backup_destinations,id'],
            'schedule_type' => ['nullable', 'string', Rule::in([
                BackupJob::SCHEDULE_HOURLY,
                BackupJob::SCHEDULE_DAILY,
                BackupJob::SCHEDULE_WEEKLY,
                BackupJob::SCHEDULE_CRON,
            ])],
            'schedule_config' => ['nullable', 'array'],
            'timezone' => ['nullable', 'string', Rule::in(\DateTimeZone::listIdentifiers())],
        ];
    }

    /**
     * The targeted stack name, or null for the "no stack" group (volumes that
     * carry no Compose or Swarm stack label).
     */
    public function stackName(): ?string
    {
        $stack = $this->input('stack');

        return is_string($stack) && trim($stack) !== '' ? $stack : null;
    }
}
