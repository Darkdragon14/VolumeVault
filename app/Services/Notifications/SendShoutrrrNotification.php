<?php

namespace App\Services\Notifications;

use App\Enums\AlertEventType;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\AlertEvent;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\NotificationChannel;
use App\Models\RestoreRun;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use App\Support\FormatBytes;
use Illuminate\Database\Eloquent\Collection;

class SendShoutrrrNotification
{
    public const IMAGE = 'ghcr.io/nicholas-fedor/shoutrrr:latest';

    public function __construct(
        private readonly DockerProcess $dockerProcess,
        private readonly ResolveNotificationChannels $resolveNotificationChannels,
    ) {}

    public function sendBackupRunFinished(BackupRun $run): void
    {
        $run->loadMissing('job.destination', 'initiatedBy');
        $failed = $run->status === BackupRun::STATUS_FAILED;

        foreach ($this->resolveNotificationChannels->forJob($run->job) as $channel) {
            if (! $failed && $channel->notification_level !== NotificationChannel::LEVEL_INFO) {
                continue;
            }

            $title = $this->backupRunTitle($run, $channel);
            $message = $this->backupRunMessage($run, $channel);
            $this->send($channel, $title, $message);
        }
    }

    /**
     * Notify the backup job's channels about a restore lifecycle event
     * (started / succeeded / failed). Reuses the job's channels and its
     * notifications_enabled toggle. Started and succeeded are info-level
     * events (info channels only); a failure reaches every channel.
     */
    public function sendRestoreRun(RestoreRun $run): void
    {
        $run->loadMissing('job.destination', 'initiatedBy');
        $failed = $run->status === RestoreRun::STATUS_FAILED;

        if ($run->job === null) {
            return;
        }

        foreach ($this->resolveNotificationChannels->forJob($run->job) as $channel) {
            if (! $failed && $channel->notification_level !== NotificationChannel::LEVEL_INFO) {
                continue;
            }

            $this->send($channel, $this->restoreRunTitle($run), $this->restoreRunMessage($run));
        }
    }

    public function sendTest(NotificationChannel $channel): DockerProcessResult
    {
        return $this->send($channel, 'VolumeVault test notification', 'If you see this message, this Shoutrrr channel works.');
    }

    public function sendAlert(Alert $alert): int
    {
        return $this->sendAlertNotification($alert, 'initial');
    }

    public function sendAlertReminder(Alert $alert): int
    {
        return $this->sendAlertNotification($alert, 'reminder');
    }

    public function sendAlertResolved(Alert $alert): int
    {
        return $this->sendAlertNotification($alert, 'resolved');
    }

    private function sendAlertNotification(Alert $alert, string $type): int
    {
        $alert->loadMissing('rule', 'subject');

        $channels = $this->alertChannels($alert);

        if ($channels->isEmpty()) {
            return 0;
        }

        $sent = 0;

        foreach ($channels as $channel) {
            $result = $this->send(
                $channel,
                $this->alertTitle($alert, $type),
                $this->alertMessage($alert, $type),
            );

            if (! $result->successful()) {
                continue;
            }

            AlertEvent::record(
                $alert,
                $type === 'reminder' ? AlertEventType::ReminderSent : AlertEventType::Notified,
                [
                    'channel_id' => $channel->id,
                    'channel' => $channel->name,
                    'type' => $type,
                    'trigger_count' => $alert->trigger_count,
                ],
            );

            $sent++;
        }

        return $sent;
    }

    /** @return Collection<int, NotificationChannel> */
    private function alertChannels(Alert $alert): Collection
    {
        if ($alert->subject instanceof BackupJob) {
            return $this->resolveNotificationChannels->forJobAlerts($alert->subject);
        }

        if ($alert->subject instanceof BackupDestination) {
            return $this->resolveNotificationChannels->forAlertRule($alert->rule);
        }

        return new Collection;
    }

    private function send(NotificationChannel $channel, string $title, string $message): DockerProcessResult
    {
        $command = [
            'docker',
            'run',
            '--rm',
            '--env',
            'SHOUTRRR_URL',
            self::IMAGE,
            'send',
            '--title',
            $title,
            '--message',
            $message,
        ];

        return $this->dockerProcess->run($command, 60, [
            'SHOUTRRR_URL' => $this->notificationUrl($channel),
        ]);
    }

    private function notificationUrl(NotificationChannel $channel): string
    {
        $url = $channel->url;

        if ($channel->service !== NotificationChannel::SERVICE_DISCORD || ! str_starts_with($url, 'discord://')) {
            return $url;
        }

        if (preg_match('/(?:[?&])splitLines=/i', $url)) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.'splitLines=No';
    }

    private function backupRunTitle(BackupRun $run, NotificationChannel $channel): string
    {
        $default = $run->status === BackupRun::STATUS_FAILED ? 'VolumeVault backup failed' : 'VolumeVault backup succeeded';

        return $this->renderTemplate($channel->title_template, $run) ?: $default;
    }

    private function restoreRunTitle(RestoreRun $run): string
    {
        return match ($run->status) {
            RestoreRun::STATUS_SUCCESS => 'VolumeVault restore succeeded',
            RestoreRun::STATUS_FAILED => 'VolumeVault restore failed',
            default => 'VolumeVault restore started',
        };
    }

    private function restoreRunMessage(RestoreRun $run): string
    {
        $lines = [
            'Job: '.($run->job?->name ?? 'Unknown'),
            'Source volume: '.$run->source_volume_name,
            'Target volume: '.$run->target_volume_name,
            'Mode: '.$run->mode,
            'Status: '.$run->status,
            'Initiated by: '.($run->initiatedBy?->name ?? 'Unknown'),
        ];

        if ($run->duration_seconds !== null) {
            $lines[] = 'Duration: '.$run->duration_seconds.'s';
        }

        if ($run->error_message) {
            $lines[] = 'Error: '.$run->error_message;
        }

        return implode("\n", $lines);
    }

    private function alertTitle(Alert $alert, string $type): string
    {
        if ($type === 'resolved') {
            return 'VolumeVault alert resolved';
        }

        return 'VolumeVault '.$alert->severity->value.' alert';
    }

    private function backupRunMessage(BackupRun $run, NotificationChannel $channel): string
    {
        $customMessage = $this->renderTemplate($channel->body_template, $run);

        if ($customMessage !== '') {
            return $customMessage;
        }

        $job = $run->job;
        $lines = [
            'Job: '.$job->name,
            'Source: '.$job->sourceName(),
            'Destination: '.($job->destination?->name ?? 'Unknown'),
            'Status: '.$run->status,
            'Trigger: '.$run->trigger,
            'Initiated by: '.($run->initiatedBy?->name ?? 'Unknown'),
        ];

        if ($run->duration_seconds !== null) {
            $lines[] = 'Duration: '.$run->duration_seconds.'s';
        }

        if ($run->backup_size_bytes !== null) {
            $lines[] = 'Backup size: '.FormatBytes::format($run->backup_size_bytes);
        }

        if ($run->error_message) {
            $lines[] = 'Error: '.$run->error_message;
        }

        return implode("\n", $lines);
    }

    private function alertMessage(Alert $alert, string $type): string
    {
        $subject = $alert->subject;
        $lines = [
            'Alert: '.$alert->rule->type->value,
            'Status: '.($type === 'resolved' ? AlertStatus::Resolved->value : $alert->status->value),
            'Severity: '.$alert->severity->value,
        ];

        if ($subject instanceof BackupJob) {
            $lines[] = 'Job: '.$subject->name;
            $lines[] = 'Source: '.$subject->sourceName();
        } elseif ($subject instanceof BackupDestination) {
            $lines[] = 'Destination: '.$subject->name;
            $lines[] = 'Provider: '.$subject->provider;
            $lines[] = 'Target: '.$subject->targetLabel();
        }

        $lines[] = 'Message: '.($type === 'resolved' ? 'Alert condition is resolved.' : $alert->message);

        if ($type === 'reminder') {
            $lines[] = 'Trigger count: '.$alert->trigger_count;
        }

        if ($type !== 'resolved' && $alert->context) {
            $lines[] = 'Context: '.$this->formatContext($alert->context);
        }

        return implode("\n", $lines);
    }

    private function renderTemplate(?string $template, BackupRun $run): string
    {
        $template = trim((string) $template);

        if ($template === '') {
            return '';
        }

        $job = $run->job;
        $values = [
            'job' => $job->name,
            'volume' => $job->sourceName(),
            'source' => $job->sourceName(),
            'destination' => $job->destination?->name ?? 'Unknown',
            'status' => $run->status,
            'trigger' => $run->trigger,
            'user' => $run->initiatedBy?->name ?? '',
            'duration' => $run->duration_seconds !== null ? $run->duration_seconds.'s' : '',
            'backup_size' => $run->backup_size_bytes !== null ? FormatBytes::format($run->backup_size_bytes) : '',
            'error' => $run->error_message ?? '',
        ];

        return preg_replace_callback('/{{\s*([a-z_]+)\s*}}/i', fn ($matches) => $values[strtolower($matches[1])] ?? $matches[0], $template);
    }

    private function formatContext(array $context): string
    {
        return collect($context)
            ->reject(fn ($value, $key): bool => str_contains((string) $key, 'secret') || str_contains((string) $key, 'token'))
            ->map(fn ($value, $key): string => $key.'='.match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_scalar($value) => (string) $value,
                default => json_encode($value, JSON_THROW_ON_ERROR),
            })
            ->implode(', ');
    }
}
