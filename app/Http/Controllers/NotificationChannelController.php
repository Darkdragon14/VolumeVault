<?php

namespace App\Http\Controllers;

use App\Concerns\PaginateWithPreference;
use App\Models\ActivityLog;
use App\Models\NotificationChannel;
use App\Services\Notifications\SendShoutrrrNotification;
use App\Services\Notifications\ShoutrrrUrlBuilder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class NotificationChannelController extends Controller
{
    use PaginateWithPreference;

    public function index(Request $request): Response
    {
        $perPage = $this->perPageForRequest($request);
        $query = NotificationChannel::with('backupJobs');
        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%");
        }
        $query->latest();

        return Inertia::render('Notifications/Index', [
            'channels' => $this->paginateForInertia($query, $perPage, fn (NotificationChannel $c): array => $c->safeForFrontend()),
            'defaultPerPage' => $request->user()->default_per_page ?? 10,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Notifications/Form', [
            'channel' => null,
            'services' => NotificationChannel::SERVICES,
        ]);
    }

    public function store(Request $request, ShoutrrrUrlBuilder $urlBuilder)
    {
        $data = $this->validated($request);
        $data['url'] = $this->buildUrl($urlBuilder, $data['service'], $request->input('config', []));

        $channel = NotificationChannel::create($this->payload($data, $request));
        $this->keepSingleDefaultChannel($channel);

        ActivityLog::record('notification_channel_created', 'Notification channel created.', $channel);

        return redirect()->route('notifications.index')->with('success', 'Notification channel created.');
    }

    public function edit(NotificationChannel $notification): Response
    {
        $notification->load('backupJobs');

        return Inertia::render('Notifications/Form', [
            'channel' => $notification->safeForFrontend(),
            'services' => NotificationChannel::SERVICES,
        ]);
    }

    public function update(Request $request, NotificationChannel $notification, ShoutrrrUrlBuilder $urlBuilder)
    {
        $data = $this->validated($request);
        $config = $request->input('config', []);
        $shouldReplaceUrl = $data['service'] !== $notification->service || $this->hasFilledConfig($config);

        if ($shouldReplaceUrl) {
            $data['url'] = $this->buildUrl($urlBuilder, $data['service'], $config);
        }

        $notification->update($this->payload($data, $request));
        $this->keepSingleDefaultChannel($notification);

        return redirect()->route('notifications.index')->with('success', 'Notification channel updated.');
    }

    public function destroy(NotificationChannel $notification)
    {
        $notification->delete();

        return redirect()->route('notifications.index')->with('success', 'Notification channel deleted.');
    }

    public function updateActive(Request $request, NotificationChannel $notification)
    {
        $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $notification->forceFill([
            'is_active' => $request->boolean('is_active'),
        ])->save();

        return back()->with('success', $notification->is_active ? 'Notification channel enabled.' : 'Notification channel disabled.');
    }

    public function test(NotificationChannel $notification, SendShoutrrrNotification $sendShoutrrrNotification)
    {
        $result = $sendShoutrrrNotification->sendTest($notification);

        $notification->forceFill([
            'last_tested_at' => now(),
            'last_test_status' => $result->successful() ? 'success' : 'failed',
            'last_test_error' => $result->successful() ? null : str($result->combinedOutput() ?: 'Shoutrrr test failed.')->limit(1000)->toString(),
        ])->save();

        return back()->with($result->successful() ? 'success' : 'error', $result->successful() ? 'Notification test sent.' : 'Notification test failed: '.$notification->last_test_error);
    }

    private function validated(Request $request): array
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

    private function payload(array $data, Request $request): array
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

    private function buildUrl(ShoutrrrUrlBuilder $urlBuilder, string $service, array $config): string
    {
        try {
            return $urlBuilder->build($service, $config);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['config' => $exception->getMessage()]);
        }
    }

    private function keepSingleDefaultChannel(NotificationChannel $channel): void
    {
        if (! $channel->is_default) {
            return;
        }

        NotificationChannel::whereKeyNot($channel->id)->update(['is_default' => false]);
    }

    private function hasFilledConfig(array $config): bool
    {
        return collect($config)->contains(fn ($value) => filled($value));
    }
}
