<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Notifications\MutateNotificationChannel;
use App\Actions\Notifications\NotificationChannelMutationBlocked;
use App\Concerns\PersistsNotificationChannel;
use App\Http\Controllers\Controller;
use App\Models\NotificationChannel;
use App\Services\Notifications\SendShoutrrrNotification;
use App\Services\Notifications\ShoutrrrUrlBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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

    public function update(Request $request, NotificationChannel $notification, ShoutrrrUrlBuilder $urlBuilder, MutateNotificationChannel $mutateNotificationChannel): JsonResponse
    {
        $data = $this->validated($request);
        $config = $this->configFromRequest($request);

        try {
            $notification = $mutateNotificationChannel->update(
                $notification,
                fn (NotificationChannel $locked): array => $this->payloadForLockedUpdate($locked, $data, $request, $urlBuilder, $config),
            );
        } catch (NotificationChannelMutationBlocked $exception) {
            throw ValidationException::withMessages(['notification' => $exception->getMessage()]);
        }

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
