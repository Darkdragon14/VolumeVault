<?php

namespace App\Actions\Alerts;

use App\Enums\AlertEventType;
use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Models\ActivityLog;
use App\Models\Alert;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\BackupJob;
use App\Services\Notifications\SendShoutrrrNotification;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class RunAllAlertChecks
{
    public function __construct(
        private readonly BackupTooOldCheck $backupTooOldCheck,
        private readonly JobNeverSucceededCheck $jobNeverSucceededCheck,
        private readonly JobInErrorTooLongCheck $jobInErrorTooLongCheck,
        private readonly BackupSizeOutOfRangeCheck $backupSizeOutOfRangeCheck,
        private readonly DestinationStorageLimitCheck $destinationStorageLimitCheck,
        private readonly AgentOfflineCheck $agentOfflineCheck,
        private readonly SendShoutrrrNotification $sendShoutrrrNotification,
        private readonly ResolveEffectiveAlertConfig $resolveEffectiveAlertConfig,
    ) {}

    public function handle(AlertRule $rule): void
    {
        if ($rule->type === AlertType::DestinationStorageLimit) {
            $result = $this->destinationStorageLimitCheck->handleWithErrors($rule);
            $findings = $result['findings'];
            $erroredSubjectKeys = $result['erroredSubjectKeys'];
        } else {
            $findings = $this->checkAction($rule->type)->handle($rule);
            $erroredSubjectKeys = [];
        }

        $activeSubjectKeys = [];

        foreach ($findings as $finding) {
            $activeSubjectKeys[$this->subjectKey($finding['subject'])] = true;
            $this->trigger($rule, $finding);
        }

        $this->resolveMissing($rule, $activeSubjectKeys, $erroredSubjectKeys);
    }

    private function checkAction(AlertType $type): AlertCheckAction
    {
        return match ($type) {
            AlertType::BackupTooOld => $this->backupTooOldCheck,
            AlertType::JobNeverSucceeded => $this->jobNeverSucceededCheck,
            AlertType::JobInErrorTooLong => $this->jobInErrorTooLongCheck,
            AlertType::BackupSizeOutOfRange => $this->backupSizeOutOfRangeCheck,
            AlertType::DestinationStorageLimit => $this->destinationStorageLimitCheck,
            AlertType::AgentOffline => $this->agentOfflineCheck,
        };
    }

    /** @param array{subject: Model, severity: mixed, message: string, context: array<string, mixed>} $finding */
    private function trigger(AlertRule $rule, array $finding): void
    {
        $subject = $finding['subject'];
        $now = now();
        $alert = Alert::firstOrNew([
            'alert_rule_id' => $rule->id,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ]);
        $wasInactive = ! $alert->exists || $alert->status !== AlertStatus::Active;
        $previousSeverity = $alert->exists ? $alert->severity : null;

        $alert->forceFill([
            'status' => AlertStatus::Active,
            'severity' => $finding['severity'],
            'message' => $finding['message'],
            'context' => $finding['context'],
            'trigger_count' => ((int) $alert->trigger_count) + 1,
            'first_triggered_at' => $alert->first_triggered_at ?: $now,
            'last_triggered_at' => $now,
            'resolved_at' => null,
        ])->save();

        AlertEvent::record($alert, AlertEventType::Triggered, [
            'severity' => $alert->severity->value,
            'message' => $alert->message,
            'trigger_count' => $alert->trigger_count,
            'context' => $alert->context,
        ]);

        if ($wasInactive) {
            $this->notifyInitial($alert);

            return;
        }

        if ($this->severityEscalated($previousSeverity, $alert->severity)) {
            $this->notifyInitial($alert);

            return;
        }

        $this->notifyReminderIfDue($alert, $rule);
    }

    private function severityEscalated(?AlertSeverity $previousSeverity, AlertSeverity $currentSeverity): bool
    {
        return $previousSeverity === AlertSeverity::Warning && $currentSeverity === AlertSeverity::Critical;
    }

    /** @param array<string, bool> $activeSubjectKeys @param list<string> $erroredSubjectKeys */
    private function resolveMissing(AlertRule $rule, array $activeSubjectKeys, array $erroredSubjectKeys = []): void
    {
        Alert::query()
            ->where('alert_rule_id', $rule->id)
            ->where('status', AlertStatus::Active->value)
            ->get()
            ->each(function (Alert $alert) use ($activeSubjectKeys, $erroredSubjectKeys): void {
                $key = $this->alertSubjectKey($alert);

                if (isset($activeSubjectKeys[$key]) || in_array($key, $erroredSubjectKeys, true)) {
                    return;
                }

                $alert->forceFill([
                    'status' => AlertStatus::Resolved,
                    'resolved_at' => now(),
                ])->save();

                AlertEvent::record($alert, AlertEventType::Resolved, [
                    'final_trigger_count' => $alert->trigger_count,
                ]);

                $this->notifyResolved($alert);
            });
    }

    private function notifyInitial(Alert $alert): void
    {
        $sent = $this->notify($alert, fn (Alert $alert): int => $this->sendShoutrrrNotification->sendAlert($alert));

        if ($sent > 0) {
            $alert->forceFill(['last_notified_at' => now()])->save();
        }
    }

    private function notifyReminderIfDue(Alert $alert, AlertRule $rule): void
    {
        $config = $this->notificationConfig($alert, $rule);

        if (! (bool) ($config['reminder_enabled'] ?? false)) {
            return;
        }

        $cooldownMinutes = max(0, (int) ($config['cooldown_minutes'] ?? config('volumevault.alerts.defaults.cooldown_minutes', 1440)));

        if ($alert->last_notified_at && $alert->last_notified_at->copy()->addMinutes($cooldownMinutes)->isFuture()) {
            return;
        }

        $sent = $this->notify($alert, fn (Alert $alert): int => $this->sendShoutrrrNotification->sendAlertReminder($alert));

        if ($sent > 0) {
            $alert->forceFill(['last_notified_at' => now()])->save();
        }
    }

    /** @return array<string, mixed> */
    private function notificationConfig(Alert $alert, AlertRule $rule): array
    {
        $alert->loadMissing('subject');

        if ($alert->subject instanceof BackupJob) {
            return $this->resolveEffectiveAlertConfig->handle($alert->subject, $rule)['config'];
        }

        return $rule->config ?? [];
    }

    private function notifyResolved(Alert $alert): void
    {
        $this->notify($alert, fn (Alert $alert): int => $this->sendShoutrrrNotification->sendAlertResolved($alert));
    }

    private function notify(Alert $alert, callable $callback): int
    {
        try {
            return $callback($alert);
        } catch (Throwable $exception) {
            ActivityLog::record('alert_notification_send_failed', 'Alert notification failed.', $alert, [
                'error' => str($exception->getMessage())->limit(1000)->toString(),
            ]);

            return 0;
        }
    }

    private function subjectKey(Model $subject): string
    {
        return $subject->getMorphClass().':'.$subject->getKey();
    }

    private function alertSubjectKey(Alert $alert): string
    {
        return $alert->subject_type.':'.$alert->subject_id;
    }
}
