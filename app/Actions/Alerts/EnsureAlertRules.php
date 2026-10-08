<?php

namespace App\Actions\Alerts;

use App\Enums\AlertType;
use App\Models\AlertRule;
use Illuminate\Support\Collection;

class EnsureAlertRules
{
    /** @return Collection<int, AlertRule> */
    public function handle(): Collection
    {
        return collect(AlertType::cases())->map(function (AlertType $type): AlertRule {
            $rule = AlertRule::firstOrNew(['type' => $type->value]);
            $config = array_replace($this->defaultConfig($type), $rule->config ?? []);

            if (! $rule->exists) {
                $rule->enabled = $type === AlertType::AgentOffline;
            }

            $rule->config = $config;

            if (! $rule->exists || $rule->isDirty()) {
                $rule->save();
            }

            return $rule;
        });
    }

    /** @return array<string, bool|int|null> */
    public function defaultConfig(AlertType $type): array
    {
        $defaults = config('volumevault.alerts.defaults', []);
        $common = [
            'check_interval_minutes' => (int) ($defaults['check_interval_minutes'] ?? 60),
            'cooldown_minutes' => (int) ($defaults['cooldown_minutes'] ?? 1440),
            'reminder_enabled' => (bool) ($defaults['reminder_enabled'] ?? false),
        ];

        return match ($type) {
            AlertType::BackupTooOld => [
                ...$common,
                'backup_too_old_days' => (int) ($defaults['backup_too_old_days'] ?? 7),
            ],
            AlertType::JobNeverSucceeded => [
                ...$common,
                'job_never_succeeded_min_runs' => (int) ($defaults['job_never_succeeded_min_runs'] ?? 3),
            ],
            AlertType::JobInErrorTooLong => [
                ...$common,
                'job_in_error_days' => (int) ($defaults['job_in_error_days'] ?? 3),
            ],
            AlertType::BackupSizeOutOfRange => [
                ...$common,
                'backup_size_out_of_range_min_bytes' => $defaults['backup_size_out_of_range_min_bytes'] ?? 1024,
                'backup_size_out_of_range_max_bytes' => $defaults['backup_size_out_of_range_max_bytes'] ?? 10737418240,
            ],
            AlertType::DestinationStorageLimit => $common,
            AlertType::AgentOffline => [
                ...$common,
                'check_interval_minutes' => (int) ($defaults['agent_offline_check_interval_minutes'] ?? 5),
                'agent_offline_minutes' => (int) ($defaults['agent_offline_minutes'] ?? 15),
            ],
        };
    }
}
