<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BackupRun;
use Illuminate\Http\JsonResponse;

class BackupRunController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => BackupRun::with(['job.destination', 'snapshotDestination'])->latest()->limit(100)->get()
                ->map(fn (BackupRun $run): array => $this->serialize($run)),
        ]);
    }

    public function show(BackupRun $backupRun): JsonResponse
    {
        return response()->json([
            'data' => $this->serialize($backupRun->load(['job.destination', 'snapshotDestination'])),
        ]);
    }

    /** @return array<string, mixed> */
    private function serialize(BackupRun $run): array
    {
        $destination = $run->destinationForRun();
        $attributes = $run->toArray();
        unset($attributes['snapshot_destination']);

        return [
            ...$attributes,
            'source_type' => $run->sourceType(),
            'source_name' => $run->sourceName(),
            'destination_id' => $run->backup_destination_id_snapshot ?? $run->job?->backup_destination_id,
            'destination_name' => $run->destinationName(),
            'destination_provider' => $run->backup_destination_provider ?: $destination?->provider,
        ];
    }
}
