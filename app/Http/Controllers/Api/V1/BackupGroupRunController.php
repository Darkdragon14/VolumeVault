<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BackupGroupRun;
use App\Models\BackupRun;
use Illuminate\Http\JsonResponse;

class BackupGroupRunController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => BackupGroupRun::with('group:id,name')->latest()->limit(100)->get(),
        ]);
    }

    public function show(BackupGroupRun $backupGroupRun): JsonResponse
    {
        $backupGroupRun->load('group:id,name');

        return response()->json([
            'data' => [
                ...$backupGroupRun->toArray(),
                'members' => $backupGroupRun->memberRuns()
                    ->with('job:id,name,volume_name,host_path,source_type')
                    ->latest()
                    ->get()
                    ->map(fn (BackupRun $run): array => [
                        'id' => $run->id,
                        'backup_job_id' => $run->backup_job_id,
                        'status' => $run->status,
                        'source_label' => $run->job?->sourceName(),
                        'started_at' => $run->started_at,
                        'finished_at' => $run->finished_at,
                        'duration_seconds' => $run->duration_seconds,
                        'backup_key' => $run->backup_key,
                        'backup_size_bytes' => $run->backup_size_bytes,
                        'error_message' => $run->error_message,
                    ])->values()->all(),
            ],
        ]);
    }
}
