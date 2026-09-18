<?php

namespace App\Actions\Alerts;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Models\ActivityLog;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\BackupDestination;
use App\Services\BackupDestinations\DestinationStorage;
use App\Support\DeploymentMode;
use App\Support\FormatBytes;
use Illuminate\Support\Facades\Cache;
use Throwable;

class DestinationStorageLimitCheck implements AlertCheckAction
{
    /** @var list<string> Subject keys that errored during the check */
    private array $erroredSubjectKeys = [];

    public function __construct(private readonly DestinationStorage $destinationStorage) {}

    /** @return array{findings: array, erroredSubjectKeys: list<string>} */
    public function handleWithErrors(AlertRule $rule): array
    {
        $this->erroredSubjectKeys = [];
        $findings = $this->handle($rule);

        return [
            'findings' => $findings,
            'erroredSubjectKeys' => $this->erroredSubjectKeys,
        ];
    }

    public function handle(AlertRule $rule): array
    {
        if (! $rule->enabled) {
            return [];
        }

        $findings = [];

        BackupDestination::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->each(function (BackupDestination $destination) use ($rule, &$findings): void {
                if (DeploymentMode::isOrchestrator() && $destination->isHostBound()) {
                    $this->erroredSubjectKeys[] = $destination->getMorphClass().':'.$destination->getKey();

                    return;
                }

                $thresholds = $this->thresholds($destination);

                if ($thresholds === null) {
                    return;
                }

                try {
                    $usage = $this->destinationStorage->storageUsage($destination);
                } catch (Throwable $exception) {
                    ActivityLog::record('destination_storage_limit_check_failed', 'Destination storage limit check failed.', $destination, [
                        'error' => str($exception->getMessage())->limit(1000)->toString(),
                    ]);

                    $this->erroredSubjectKeys[] = $destination->getMorphClass().':'.$destination->getKey();

                    if ($existing = $this->activeAlert($rule, $destination)) {
                        $findings[] = [
                            'subject' => $destination,
                            'severity' => $existing->severity,
                            'message' => $existing->message,
                            'context' => [
                                ...($existing->context ?? []),
                                'last_storage_check_error' => str($exception->getMessage())->limit(500)->toString(),
                            ],
                        ];
                    }

                    return;
                }

                $usedBytes = (int) $usage['used_bytes'];
                $delta = $this->recordUsageDelta($destination, $usedBytes);
                $severity = $this->severity($usedBytes, $thresholds);

                if ($severity === null) {
                    return;
                }

                $findings[] = [
                    'subject' => $destination,
                    'severity' => $severity,
                    'message' => 'Destination "'.$destination->name.'" is using '.FormatBytes::format($usedBytes).' of backup storage.',
                    'context' => [
                        'destination_id' => $destination->id,
                        'destination' => $destination->name,
                        'provider' => $destination->provider,
                        'target' => $destination->targetLabel(),
                        'used_bytes' => $usedBytes,
                        'used' => FormatBytes::format($usedBytes),
                        'warning_threshold_bytes' => $thresholds['warning'],
                        'warning_threshold' => $thresholds['warning'] !== null ? FormatBytes::format($thresholds['warning']) : null,
                        'critical_threshold_bytes' => $thresholds['critical'],
                        'critical_threshold' => $thresholds['critical'] !== null ? FormatBytes::format($thresholds['critical']) : null,
                        'threshold' => $severity === AlertSeverity::Critical ? 'critical' : 'warning',
                        'object_count' => (int) $usage['object_count'],
                        'previous_used_bytes' => $delta['previous_used_bytes'],
                        'previous_used' => $delta['previous_used_bytes'] !== null ? FormatBytes::format($delta['previous_used_bytes']) : null,
                        'delta_bytes' => $delta['delta_bytes'],
                        'delta' => $delta['delta_bytes'] !== null ? FormatBytes::formatSigned($delta['delta_bytes']) : null,
                    ],
                ];
            });

        return $findings;
    }

    /** @return array{warning: int|null, critical: int|null}|null */
    private function thresholds(BackupDestination $destination): ?array
    {
        $warning = $this->threshold($destination, 'storage_limit_warning_bytes');
        $critical = $this->threshold($destination, 'storage_limit_critical_bytes');

        if ($warning === null && $critical === null) {
            return null;
        }

        if ($warning !== null && $critical !== null && $critical < $warning) {
            return null;
        }

        return ['warning' => $warning, 'critical' => $critical];
    }

    private function threshold(BackupDestination $destination, string $key): ?int
    {
        $value = $destination->setting($key);

        if ($value === null || $value === '') {
            return null;
        }

        return max(0, (int) $value);
    }

    /** @param array{warning: int|null, critical: int|null} $thresholds */
    private function severity(int $usedBytes, array $thresholds): ?AlertSeverity
    {
        if ($thresholds['critical'] !== null && $usedBytes >= $thresholds['critical']) {
            return AlertSeverity::Critical;
        }

        if ($thresholds['warning'] !== null && $usedBytes >= $thresholds['warning']) {
            return AlertSeverity::Warning;
        }

        return null;
    }

    /** @return array{previous_used_bytes: int|null, delta_bytes: int|null} */
    private function recordUsageDelta(BackupDestination $destination, int $usedBytes): array
    {
        $cacheKey = 'destination_storage_delta_baseline_'.$destination->id;
        $previousUsedBytes = Cache::get($cacheKey);

        Cache::put($cacheKey, $usedBytes, now()->addDays(30));

        if (! is_numeric($previousUsedBytes)) {
            return ['previous_used_bytes' => null, 'delta_bytes' => null];
        }

        $previousUsedBytes = (int) $previousUsedBytes;

        return [
            'previous_used_bytes' => $previousUsedBytes,
            'delta_bytes' => $usedBytes - $previousUsedBytes,
        ];
    }

    private function activeAlert(AlertRule $rule, BackupDestination $destination): ?Alert
    {
        return Alert::query()
            ->where('alert_rule_id', $rule->id)
            ->where('subject_type', $destination->getMorphClass())
            ->where('subject_id', $destination->getKey())
            ->where('status', AlertStatus::Active->value)
            ->first();
    }
}
