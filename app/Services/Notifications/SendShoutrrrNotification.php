<?php

namespace App\Services\Notifications;

use App\Models\BackupRun;
use App\Models\NotificationChannel;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;

class SendShoutrrrNotification
{
    public const IMAGE = 'ghcr.io/nicholas-fedor/shoutrrr:latest';

    public function __construct(
        private readonly DockerProcess $dockerProcess,
        private readonly ResolveNotificationChannels $resolveNotificationChannels,
    ) {}

    public function sendBackupRunFinished(BackupRun $run): void
    {
        $run->loadMissing('job.destination');
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

    public function sendTest(NotificationChannel $channel): DockerProcessResult
    {
        return $this->send($channel, 'VolumeVault test notification', 'If you see this message, this Shoutrrr channel works.');
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

    private function backupRunMessage(BackupRun $run, NotificationChannel $channel): string
    {
        $customMessage = $this->renderTemplate($channel->body_template, $run);

        if ($customMessage !== '') {
            return $customMessage;
        }

        $job = $run->job;
        $lines = [
            'Job: '.$job->name,
            'Volume: '.$job->volume_name,
            'Destination: '.($job->destination?->name ?? 'Unknown'),
            'Status: '.$run->status,
            'Trigger: '.$run->trigger,
        ];

        if ($run->duration_seconds !== null) {
            $lines[] = 'Duration: '.$run->duration_seconds.'s';
        }

        if ($run->error_message) {
            $lines[] = 'Error: '.$run->error_message;
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
            'volume' => $job->volume_name,
            'destination' => $job->destination?->name ?? 'Unknown',
            'status' => $run->status,
            'trigger' => $run->trigger,
            'duration' => $run->duration_seconds !== null ? $run->duration_seconds.'s' : '',
            'error' => $run->error_message ?? '',
        ];

        return preg_replace_callback('/{{\s*([a-z_]+)\s*}}/i', fn ($matches) => $values[strtolower($matches[1])] ?? $matches[0], $template);
    }
}
