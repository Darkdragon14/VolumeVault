<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerVolume;
use App\Models\RestoreRun;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $lastBackupRun = BackupRun::with('job')->latest()->first();
        $nextJob = BackupJob::with('destination')
            ->where('status', BackupJob::STATUS_ACTIVE)
            ->whereNotNull('next_run_at')
            ->orderBy('next_run_at')
            ->first();

        return response()->json([
            'data' => [
                'stats' => [
                    'total_volumes' => DockerVolume::count(),
                    'existing_volumes' => DockerVolume::where('exists', true)->count(),
                    'missing_volumes' => DockerVolume::where('exists', false)->count(),
                    'active_jobs' => BackupJob::where('status', BackupJob::STATUS_ACTIVE)->count(),
                    'paused_jobs' => BackupJob::where('status', BackupJob::STATUS_PAUSED)->count(),
                    'error_jobs' => BackupJob::where('status', BackupJob::STATUS_ERROR)->count(),
                    'last_backup_run_status' => $lastBackupRun?->status,
                    'next_scheduled_backup' => $nextJob?->next_run_at,
                ],
                'recent_backup_runs' => BackupRun::with('job')->latest()->limit(8)->get(),
                'recent_restore_runs' => RestoreRun::with('job')->latest()->limit(8)->get(),
                'jobs_with_errors' => BackupJob::with('destination')
                    ->where('status', BackupJob::STATUS_ERROR)
                    ->latest()
                    ->limit(8)
                    ->get()
                    ->map(fn (BackupJob $job) => $this->job($job)),
            ],
        ]);
    }

    private function job(BackupJob $job): array
    {
        return [
            ...$job->toArray(),
            'destination' => $job->destination?->safeForFrontend(),
        ];
    }
}
