<?php

namespace App\Actions\Alerts;

use App\Http\Requests\BackupJobRequest;
use App\Models\BackupJob;

class SyncBackupJobAlertSettings
{
    public function payload(BackupJobRequest $request, ?BackupJob $job = null): array
    {
        return [
            'use_custom_alert_settings' => $request->has('use_custom_alert_settings') ? $request->boolean('use_custom_alert_settings') : (bool) ($job?->use_custom_alert_settings ?? false),
            'alert_notifications_enabled' => $request->has('alert_notifications_enabled') ? $request->boolean('alert_notifications_enabled') : (bool) ($job?->alert_notifications_enabled ?? true),
        ];
    }

    public function handle(BackupJob $job, BackupJobRequest $request): void
    {
        if ($request->has('use_custom_alert_settings') && ! $request->boolean('use_custom_alert_settings')) {
            $job->alertConfigs()->delete();

            return;
        }

        if (! $job->use_custom_alert_settings || ! $request->alertConfigsWerePresentInOriginalInput()) {
            return;
        }

        $configs = collect($request->input('alert_configs') ?? []);
        $alertRuleIds = $configs->pluck('alert_rule_id')->map(fn ($id): int => (int) $id)->all();

        if ($alertRuleIds === []) {
            $job->alertConfigs()->delete();
        } else {
            $job->alertConfigs()->whereNotIn('alert_rule_id', $alertRuleIds)->delete();
        }

        $configs
            ->each(function (array $config) use ($job): void {
                $job->alertConfigs()->updateOrCreate(
                    ['alert_rule_id' => (int) $config['alert_rule_id']],
                    [
                        'enabled' => array_key_exists('enabled', $config) && $config['enabled'] !== null ? (bool) $config['enabled'] : null,
                        'config' => $this->configPayload($config['config'] ?? []),
                    ],
                );
            });
    }

    private function configPayload(array $config): array
    {
        return collect($config)
            ->only([
                'cooldown_minutes',
                'reminder_enabled',
                'backup_too_old_days',
                'job_never_succeeded_min_runs',
                'job_in_error_days',
                'backup_size_out_of_range_min_bytes',
                'backup_size_out_of_range_max_bytes',
            ])
            ->all();
    }
}
