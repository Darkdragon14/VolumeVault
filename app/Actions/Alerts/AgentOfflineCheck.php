<?php

namespace App\Actions\Alerts;

use App\Enums\AlertSeverity;
use App\Models\AlertRule;
use App\Models\DockerHost;

class AgentOfflineCheck implements AlertCheckAction
{
    public function handle(AlertRule $rule): array
    {
        if (! $rule->enabled) {
            return [];
        }

        $minutes = max(1, (int) ($rule->config['agent_offline_minutes'] ?? config('volumevault.alerts.defaults.agent_offline_minutes', 15)));
        $cutoff = now()->subMinutes($minutes);
        $findings = [];

        DockerHost::query()
            ->where('driver', DockerHost::DRIVER_AGENT)
            ->whereNotNull('agent_registered_at')
            ->whereNull('agent_revoked_at')
            ->whereNull('maintenance_requested_at')
            ->where(function ($query) use ($cutoff): void {
                $query->where('last_seen_at', '<=', $cutoff)
                    ->orWhere(fn ($query) => $query->whereNull('last_seen_at')->where('agent_registered_at', '<=', $cutoff));
            })
            ->cursor()
            ->each(function (DockerHost $host) use ($minutes, &$findings): void {
                $findings[] = [
                    'subject' => $host,
                    'severity' => AlertSeverity::Critical,
                    'message' => 'Agent "'.$host->name.'" has not been seen for at least '.$minutes.' minutes.',
                    'context' => [
                        'docker_host_id' => $host->id,
                        'threshold_minutes' => $minutes,
                        'last_seen_at' => $host->last_seen_at?->toISOString(),
                        'agent_registered_at' => $host->agent_registered_at->toISOString(),
                    ],
                ];
            });

        return $findings;
    }
}
