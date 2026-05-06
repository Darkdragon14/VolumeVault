<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\BackupJob;
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
    public function index(): Response
    {
        return Inertia::render('Notifications/Index', [
            'channels' => NotificationChannel::with('backupJobs')
                ->latest()
                ->get()
                ->map->safeForFrontend(),
            'jobs' => $this->jobsForFrontend(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Notifications/Form', [
            'channel' => null,
            'services' => NotificationChannel::SERVICES,
            'jobs' => $this->jobsForFrontend(),
        ]);
    }

    public function store(Request $request, ShoutrrrUrlBuilder $urlBuilder)
    {
        $data = $this->validated($request);
        $data['url'] = $this->buildUrl($urlBuilder, $data['service'], $request->input('config', []));

        $channel = NotificationChannel::create($this->payload($data, $request));
        $this->syncJobs($channel, $request);

        ActivityLog::record('notification_channel_created', 'Notification channel created.', $channel);

        return redirect()->route('notifications.index')->with('success', 'Notification channel created.');
    }

    public function edit(NotificationChannel $notification): Response
    {
        $notification->load('backupJobs');

        return Inertia::render('Notifications/Form', [
            'channel' => $notification->safeForFrontend(),
            'services' => NotificationChannel::SERVICES,
            'jobs' => $this->jobsForFrontend(),
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
        $this->syncJobs($notification, $request);

        return redirect()->route('notifications.index')->with('success', 'Notification channel updated.');
    }

    public function destroy(NotificationChannel $notification)
    {
        $notification->delete();

        return redirect()->route('notifications.index')->with('success', 'Notification channel deleted.');
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
            'scope' => ['required', 'string', Rule::in(NotificationChannel::SCOPES)],
            'title_template' => ['nullable', 'string', 'max:255'],
            'body_template' => ['nullable', 'string', 'max:4000'],
            'is_active' => ['boolean'],
            'backup_job_ids' => ['nullable', 'array'],
            'backup_job_ids.*' => ['integer', 'exists:backup_jobs,id'],
            'config' => ['nullable', 'array'],
        ]);
    }

    private function payload(array $data, Request $request): array
    {
        return array_filter([
            'name' => $data['name'],
            'service' => $data['service'],
            'url' => $data['url'] ?? null,
            'notification_level' => $data['notification_level'],
            'scope' => $data['scope'],
            'title_template' => $data['title_template'] ?? null,
            'body_template' => $data['body_template'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ], fn ($value) => $value !== null);
    }

    private function buildUrl(ShoutrrrUrlBuilder $urlBuilder, string $service, array $config): string
    {
        try {
            return $urlBuilder->build($service, $config);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['config' => $exception->getMessage()]);
        }
    }

    private function syncJobs(NotificationChannel $channel, Request $request): void
    {
        if ($channel->scope !== NotificationChannel::SCOPE_SPECIFIC) {
            $channel->backupJobs()->sync([]);

            return;
        }

        $channel->backupJobs()->sync($request->input('backup_job_ids', []));
    }

    private function hasFilledConfig(array $config): bool
    {
        return collect($config)->contains(fn ($value) => filled($value));
    }

    private function jobsForFrontend(): array
    {
        return BackupJob::orderBy('name')->get(['id', 'name', 'volume_name'])->toArray();
    }
}
