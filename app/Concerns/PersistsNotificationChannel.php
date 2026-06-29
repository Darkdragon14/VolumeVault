<?php

namespace App\Concerns;

use App\Models\NotificationChannel;
use App\Services\Notifications\ShoutrrrUrlBuilder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Shared validation, Shoutrrr URL building and persistence helpers for notification
 * channels, used by both the web controller and the API V1 controller so the two
 * stay in lockstep (the webhook service is exercised identically through either).
 */
trait PersistsNotificationChannel
{
    protected function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'service' => ['required', 'string', Rule::in(NotificationChannel::SERVICES)],
            'notification_level' => ['required', 'string', Rule::in(NotificationChannel::LEVELS)],
            'scope' => ['nullable', 'string', Rule::in(NotificationChannel::SCOPES)],
            'title_template' => ['nullable', 'string', 'max:255'],
            'body_template' => ['nullable', 'string', 'max:4000'],
            'restore_title_template' => ['nullable', 'string', 'max:255'],
            'restore_body_template' => ['nullable', 'string', 'max:4000'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
            'config' => ['nullable', 'array'],
        ]);
    }

    protected function payload(array $data, Request $request): array
    {
        // Filter out null url/scope so an update without a new URL keeps the saved
        // encrypted value. Template fields are intentionally excluded from the filter:
        // clearing a template sends an empty string, which Laravel coerces to null, and
        // that null must reach the database to actually wipe the previous template.
        return array_merge(array_filter([
            'name' => $data['name'],
            'service' => $data['service'],
            'url' => $data['url'] ?? null,
            'notification_level' => $data['notification_level'],
            'scope' => $data['scope'] ?? NotificationChannel::SCOPE_ALL,
            'is_active' => $request->boolean('is_active', true),
            'is_default' => $request->boolean('is_default'),
        ], fn ($value) => $value !== null), [
            'title_template' => $data['title_template'] ?? null,
            'body_template' => $data['body_template'] ?? null,
            'restore_title_template' => $data['restore_title_template'] ?? null,
            'restore_body_template' => $data['restore_body_template'] ?? null,
        ]);
    }

    protected function buildUrl(ShoutrrrUrlBuilder $urlBuilder, string $service, array $config): string
    {
        try {
            return $urlBuilder->build($service, $config);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['config' => $exception->getMessage()]);
        }
    }

    protected function keepSingleDefaultChannel(NotificationChannel $channel): void
    {
        if (! $channel->is_default) {
            return;
        }

        NotificationChannel::whereKeyNot($channel->id)->update(['is_default' => false]);
    }

    protected function hasFilledConfig(array $config): bool
    {
        return collect($config)->contains(fn ($value) => filled($value));
    }
}
