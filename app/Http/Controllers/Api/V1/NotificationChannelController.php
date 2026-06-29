<?php

namespace App\Http\Controllers\Api\V1;

use App\Concerns\PersistsNotificationChannel;
use App\Http\Controllers\Controller;
use App\Models\NotificationChannel;
use App\Services\Notifications\SendShoutrrrNotification;
use App\Services\Notifications\ShoutrrrUrlBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationChannelController extends Controller
{
    use PersistsNotificationChannel;

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => NotificationChannel::with('backupJobs')
                ->latest()
                ->get()
                ->map->safeForFrontend(),
        ]);
    }

    public function show(NotificationChannel $notification): JsonResponse
    {
        return response()->json(['data' => $notification->load('backupJobs')->safeForFrontend()]);
    }

    public function update(Request $request, NotificationChannel $notification, ShoutrrrUrlBuilder $urlBuilder): JsonResponse
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

        return response()->json(['data' => $notification->fresh()->load('backupJobs')->safeForFrontend()]);
    }

    public function test(NotificationChannel $notification, SendShoutrrrNotification $sendShoutrrrNotification): JsonResponse
    {
        $result = $sendShoutrrrNotification->sendTest($notification);

        $notification->forceFill([
            'last_tested_at' => now(),
            'last_test_status' => $result->successful() ? 'success' : 'failed',
            'last_test_error' => $result->successful() ? null : str($result->combinedOutput() ?: 'Shoutrrr test failed.')->limit(1000)->toString(),
        ])->save();

        return response()->json([
            'data' => [
                'ok' => $result->successful(),
                'message' => $result->successful() ? 'Notification test sent.' : 'Notification test failed.',
                'channel' => $notification->fresh()->safeForFrontend(),
            ],
        ], $result->successful() ? 200 : 422);
    }
}
