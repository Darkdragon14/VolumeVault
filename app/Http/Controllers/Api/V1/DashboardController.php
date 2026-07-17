<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesApiHosts;
use App\Http\Controllers\Controller;
use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\DockerVolume;
use App\Models\RestoreRun;
use App\Services\Volumes\VolumeBackupSummaries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ResolvesApiHosts;

    public function __invoke(Request $request, VolumeBackupSummaries $volumeBackupSummaries): JsonResponse
    {
        $scope = $this->resolveHostScope($request);
        $volumeSummaries = $volumeBackupSummaries->forVolumes($this->applyHostScope(DockerVolume::query(), $scope)->get());
        $coverageStats = $volumeBackupSummaries->coverageStats($volumeSummaries);
        // Standalone runs only: a group's outcome is shown by the group widgets,
        // and a member run can be success while its group run aggregated to failed.
        $lastBackupRun = $this->applyHostScope(BackupRun::with('job')->whereNull('backup_group_run_id')->latest(), $scope)->first();
        $lastSuccessfulBackupRun = $this->applyHostScope(BackupRun::query()
            ->whereNull('backup_group_run_id')
            ->where('status', BackupRun::STATUS_SUCCESS)
            ->orderByDesc('finished_at')
            ->orderByDesc('created_at'), $scope)
            ->first();
        // Group counterpart of the standalone stat above, aggregated at read time
        // because member archive sizes are recorded asynchronously and can lag the
        // group run's finalization (stays null, never 0, until at least one lands).
        $lastSuccessfulGroupBackupSize = BackupGroupRun::lastSuccessfulTotalBackupSize();
        $nextJob = $this->applyHostScope(BackupJob::query()
            ->where('status', BackupJob::STATUS_ACTIVE)
            ->whereNull('backup_job_group_id')
            ->whereNotNull('next_run_at')
            ->orderBy('next_run_at'), $scope)
            ->first();
        $nextGroup = BackupJobGroup::query()
            ->where('status', BackupJobGroup::STATUS_ACTIVE)
            ->whereNotNull('next_run_at')
            ->orderBy('next_run_at')
            ->first();
        $nextScheduledBackup = collect([$nextJob?->next_run_at, $nextGroup?->next_run_at])
            ->filter()
            ->sortBy(fn ($date) => $date->getTimestamp())
            ->first();

        return response()->json([
            'data' => [
                'stats' => [
                    'total_volumes' => $this->applyHostScope(DockerVolume::query(), $scope)->count(),
                    'existing_volumes' => $this->applyHostScope(DockerVolume::where('exists', true), $scope)->count(),
                    'missing_volumes' => $this->applyHostScope(DockerVolume::where('exists', false), $scope)->count(),
                    'backed_up_volumes' => $coverageStats['backed_up_volumes'],
                    'configured_volumes' => $coverageStats['configured_volumes'],
                    'unprotected_volumes' => $coverageStats['unprotected_volumes'],
                    'total_jobs' => $this->applyHostScope(BackupJob::whereNull('backup_job_group_id'), $scope)->count(),
                    'active_jobs' => $this->applyHostScope(BackupJob::whereNull('backup_job_group_id')->where('status', BackupJob::STATUS_ACTIVE), $scope)->count(),
                    'paused_jobs' => $this->applyHostScope(BackupJob::whereNull('backup_job_group_id')->where('status', BackupJob::STATUS_PAUSED), $scope)->count(),
                    'error_jobs' => $this->applyHostScope(BackupJob::whereNull('backup_job_group_id')->where('status', BackupJob::STATUS_ERROR), $scope)->count(),
                    'total_groups' => BackupJobGroup::count(),
                    'active_groups' => BackupJobGroup::where('status', BackupJobGroup::STATUS_ACTIVE)->count(),
                    'paused_groups' => BackupJobGroup::where('status', BackupJobGroup::STATUS_PAUSED)->count(),
                    'error_groups' => BackupJobGroup::where('status', BackupJobGroup::STATUS_ERROR)->count(),
                    'last_backup_run_status' => $lastBackupRun?->status,
                    'last_successful_backup_size' => $lastSuccessfulBackupRun?->backup_size_bytes,
                    'last_successful_group_backup_size' => $lastSuccessfulGroupBackupSize,
                    'next_scheduled_backup' => $nextScheduledBackup,
                ],
                'recent_backup_runs' => $this->applyHostScope(BackupRun::with('job', 'host')->whereNull('backup_group_run_id')->latest(), $scope)->limit(8)->get()->map(fn (BackupRun $run) => $this->backupRun($run)),
                'recent_group_runs' => BackupGroupRun::with('group')->withTotalBackupSize()->latest()->limit(8)->get(),
                'recent_restore_runs' => $this->applyHostScope(RestoreRun::with('job', 'host')->latest(), $scope)->limit(8)->get()->map(fn (RestoreRun $run) => $this->restoreRun($run)),
                'jobs_with_errors' => $this->applyHostScope(BackupJob::with('destination', 'host')
                    ->whereNull('backup_job_group_id')
                    ->where('status', BackupJob::STATUS_ERROR)
                    ->latest(), $scope)
                    ->limit(8)
                    ->get()
                    ->map(fn (BackupJob $job) => $this->job($job)),
                'groups_with_errors' => BackupJobGroup::where('status', BackupJobGroup::STATUS_ERROR)
                    ->latest()
                    ->limit(8)
                    ->get(),
            ],
        ]);
    }

    private function job(BackupJob $job): array
    {
        $data = $job->toArray();
        unset($data['destination'], $data['host']);

        return [
            ...$data,
            'host' => $this->safeHost($job->host),
            'destination' => $job->destination?->safeForFrontend(),
        ];
    }

    private function backupRun(BackupRun $run): array
    {
        $data = $run->toArray();
        unset($data['host']);

        return [
            ...$data,
            'host' => $this->safeHost($run->host),
        ];
    }

    private function restoreRun(RestoreRun $run): array
    {
        $data = $run->toArray();
        unset($data['host']);

        return [
            ...$data,
            'host' => $this->safeHost($run->host),
        ];
    }
}
