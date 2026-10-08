<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesBackupSizeRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAlertRulesRequest extends FormRequest
{
    use ValidatesBackupSizeRange;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rules' => ['required', 'array'],
            'rules.*.id' => ['required', 'integer', 'distinct', 'exists:alert_rules,id'],
            'rules.*.enabled' => ['required', 'boolean'],
            'rules.*.notification_channel_ids' => ['nullable', 'array'],
            'rules.*.notification_channel_ids.*' => ['integer', 'distinct', 'exists:notification_channels,id'],
            'rules.*.config' => ['required', 'array'],
            'rules.*.config.check_interval_minutes' => ['required', 'integer', 'min:1'],
            'rules.*.config.cooldown_minutes' => ['required', 'integer', 'min:0'],
            'rules.*.config.reminder_enabled' => ['required', 'boolean'],
            'rules.*.config.backup_too_old_days' => ['nullable', 'integer', 'min:1'],
            'rules.*.config.job_never_succeeded_min_runs' => ['nullable', 'integer', 'min:1'],
            'rules.*.config.job_in_error_days' => ['nullable', 'integer', 'min:1'],
            'rules.*.config.agent_offline_minutes' => ['nullable', 'integer', 'min:1'],
            'rules.*.config.backup_size_out_of_range_min_bytes' => ['nullable', 'integer', 'min:0'],
            'rules.*.config.backup_size_out_of_range_max_bytes' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateBackupSizeRanges(
                $validator,
                $this->input('rules', []),
                fn (int $index): string => 'rules.'.$index.'.config.backup_size_out_of_range_max_bytes',
            );
        });
    }

    /** @return array<int, array{id: int, enabled: bool, notification_channel_ids: array<int, int>, config: array<string, bool|int|null>}> */
    public function alertRules(): array
    {
        return collect($this->validated('rules'))
            ->map(fn (array $rule): array => [
                'id' => (int) $rule['id'],
                'enabled' => (bool) $rule['enabled'],
                'notification_channel_ids' => $this->notificationChannelIds($rule['notification_channel_ids'] ?? []),
                'config' => $this->normalizeConfig($rule['config'] ?? []),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, bool|int|null> */
    private function normalizeConfig(array $config): array
    {
        return collect([
            'check_interval_minutes',
            'cooldown_minutes',
            'reminder_enabled',
            'backup_too_old_days',
            'job_never_succeeded_min_runs',
            'job_in_error_days',
            'agent_offline_minutes',
            'backup_size_out_of_range_min_bytes',
            'backup_size_out_of_range_max_bytes',
        ])->filter(fn (string $key): bool => array_key_exists($key, $config))
            ->mapWithKeys(fn (string $key): array => [$key => $config[$key] === null ? null : ($key === 'reminder_enabled' ? (bool) $config[$key] : (int) $config[$key])])
            ->all();
    }

    /** @return array<int, int> */
    private function notificationChannelIds(array $ids): array
    {
        return collect($ids)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
