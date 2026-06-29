<?php

namespace App\Http\Controllers;

use App\Concerns\PaginateWithPreference;
use App\Concerns\PersistsNotificationChannel;
use App\Models\ActivityLog;
use App\Models\NotificationChannel;
use App\Services\Notifications\SendShoutrrrNotification;
use App\Services\Notifications\ShoutrrrUrlBuilder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationChannelController extends Controller
{
    use PaginateWithPreference;
    use PersistsNotificationChannel;

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
        $data['url'] = $this->buildUrl($urlBuilder, $data['service'], $this->configFromRequest($request));

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
        $config = $this->configFromRequest($request);
        $shouldReplaceUrl = $data['service'] !== $notification->service || $this->hasFilledConfig($config);

        if ($shouldReplaceUrl) {
            $existing = $this->existingWebhookMap($notification, $data['service']);
            $data['url'] = $this->buildUrl($urlBuilder, $data['service'], $config, $existing);
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
}
