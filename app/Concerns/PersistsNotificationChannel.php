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
        // name / service / notification_level are required by validation, so they
        // always apply.
        $payload = [
            'name' => $data['name'],
            'service' => $data['service'],
            'notification_level' => $data['notification_level'],
        ];

        // A rebuilt URL is only present when the caller regenerated it; otherwise the
        // saved encrypted URL is left untouched.
        if (isset($data['url'])) {
            $payload['url'] = $data['url'];
        }

        // Optional fields are written only when the request actually carries them, so a
        // partial API update never silently resets scope, the toggles, or the templates.
        // On create, omitted fields fall back to the column defaults. The web form always
        // submits these (templates are sent as empty strings when cleared, so the key is
        // still present and the template is wiped as before), so its behaviour is unchanged.
        if ($request->has('scope')) {
            $payload['scope'] = $data['scope'] ?? NotificationChannel::SCOPE_ALL;
        }

        if ($request->has('is_active')) {
            $payload['is_active'] = $request->boolean('is_active');
        }

        if ($request->has('is_default')) {
            $payload['is_default'] = $request->boolean('is_default');
        }

        foreach (['title_template', 'body_template', 'restore_title_template', 'restore_body_template'] as $field) {
            if ($request->has($field)) {
                $payload[$field] = $data[$field] ?? null;
            }
        }

        return $payload;
    }

    /**
     * Read the guided-setup config as an array. The field is nullable in validation,
     * so an explicit `config: null` (or a non-array) is normalised to an empty array
     * before it reaches the array-typed builder/helpers.
     */
    protected function configFromRequest(Request $request): array
    {
        $config = $request->input('config');

        return is_array($config) ? $config : [];
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
