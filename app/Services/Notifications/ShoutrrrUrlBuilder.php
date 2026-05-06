<?php

namespace App\Services\Notifications;

use App\Models\NotificationChannel;
use InvalidArgumentException;

class ShoutrrrUrlBuilder
{
    public function build(string $service, array $config): string
    {
        return match ($service) {
            NotificationChannel::SERVICE_DISCORD => $this->discord($config),
            NotificationChannel::SERVICE_TELEGRAM => $this->telegram($config),
            NotificationChannel::SERVICE_NTFY => $this->ntfy($config),
            NotificationChannel::SERVICE_GOTIFY => $this->gotify($config),
            NotificationChannel::SERVICE_SMTP => $this->smtp($config),
            NotificationChannel::SERVICE_ADVANCED => $this->advanced($config),
            default => throw new InvalidArgumentException('Unsupported notification service.'),
        };
    }

    private function discord(array $config): string
    {
        $webhookUrl = trim((string) ($config['webhook_url'] ?? ''));
        $parts = parse_url($webhookUrl);

        if (! is_array($parts) || empty($parts['path'])) {
            throw new InvalidArgumentException('Paste the full Discord webhook URL.');
        }

        $segments = array_values(array_filter(explode('/', trim($parts['path'], '/'))));
        $webhooksIndex = array_search('webhooks', $segments, true);

        if ($webhooksIndex === false || empty($segments[$webhooksIndex + 1]) || empty($segments[$webhooksIndex + 2])) {
            throw new InvalidArgumentException('Discord webhook URL should look like https://discord.com/api/webhooks/{id}/{token}.');
        }

        $webhookId = $segments[$webhooksIndex + 1];
        $token = $segments[$webhooksIndex + 2];

        return 'discord://'.$this->encode($token).'@'.$this->encode($webhookId).'?'.http_build_query([
            'username' => $config['username'] ?? 'VolumeVault',
            'splitLines' => 'No',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function telegram(array $config): string
    {
        $token = trim((string) ($config['token'] ?? ''));
        $chats = trim((string) ($config['chats'] ?? ''));

        if ($token === '' || $chats === '') {
            throw new InvalidArgumentException('Telegram needs a bot token and at least one chat or channel.');
        }

        return 'telegram://'.$this->encode($token).'@telegram?'.http_build_query([
            'chats' => $chats,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function ntfy(array $config): string
    {
        $topic = trim((string) ($config['topic'] ?? ''));
        $host = trim((string) ($config['host'] ?? 'ntfy.sh')) ?: 'ntfy.sh';

        if ($topic === '') {
            throw new InvalidArgumentException('Ntfy needs a topic.');
        }

        $auth = '';
        if (filled($config['username'] ?? null) || filled($config['password'] ?? null)) {
            $auth = $this->encode((string) ($config['username'] ?? '')).':'.$this->encode((string) ($config['password'] ?? '')).'@';
        }

        $query = array_filter([
            'scheme' => $config['scheme'] ?? 'https',
            'title' => $config['title'] ?? 'VolumeVault',
        ]);

        return 'ntfy://'.$auth.$this->host($host).'/'.$this->encode($topic).($query ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    }

    private function gotify(array $config): string
    {
        $host = trim((string) ($config['host'] ?? ''));
        $token = trim((string) ($config['token'] ?? ''));

        if ($host === '' || $token === '') {
            throw new InvalidArgumentException('Gotify needs the server host and application token.');
        }

        return 'gotify://'.$this->host($host).'/'.$this->encode($token).'?'.http_build_query([
            'title' => $config['title'] ?? 'VolumeVault',
            'priority' => $config['priority'] ?? 5,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function smtp(array $config): string
    {
        $host = trim((string) ($config['host'] ?? ''));
        $from = trim((string) ($config['from'] ?? ''));
        $to = trim((string) ($config['to'] ?? ''));

        if ($host === '' || $from === '' || $to === '') {
            throw new InvalidArgumentException('Email needs SMTP host, from address and recipient.');
        }

        $username = $this->encode((string) ($config['username'] ?? ''));
        $password = $this->encode((string) ($config['password'] ?? ''));
        $auth = $username !== '' || $password !== '' ? $username.':'.$password.'@' : '';
        $port = (int) ($config['port'] ?? 587);

        return 'smtp://'.$auth.$this->host($host).':'.$port.'/?'.http_build_query([
            'from' => $from,
            'to' => $to,
            'subject' => $config['subject'] ?? 'VolumeVault backup notification',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function advanced(array $config): string
    {
        $url = trim((string) ($config['url'] ?? ''));

        if ($url === '' || ! preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $url)) {
            throw new InvalidArgumentException('Advanced mode needs a valid Shoutrrr URL.');
        }

        return $url;
    }

    private function host(string $host): string
    {
        return preg_replace('#^https?://#', '', rtrim($host, '/')) ?: $host;
    }

    private function encode(string $value): string
    {
        return rawurlencode($value);
    }
}
