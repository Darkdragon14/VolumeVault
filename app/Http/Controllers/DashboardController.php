<?php

namespace App\Http\Controllers;

use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\DockerVolume;
use App\Models\RestoreRun;
use App\Services\Volumes\VolumeBackupSummaries;
use App\Support\DashboardWidgets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, VolumeBackupSummaries $volumeBackupSummaries): Response
    {
        $preferences = DashboardWidgets::normalize($request->user()->dashboard_preferences);

        $volumeSummaries = $volumeBackupSummaries->forVolumes(DockerVolume::query()->get());
        $coverageStats = $volumeBackupSummaries->coverageStats($volumeSummaries);
        // Standalone runs only: a group's outcome is shown by the group widgets,
        // and a member run can be success while its group run aggregated to failed.
        $lastBackupRun = BackupRun::with('job')->whereNull('backup_group_run_id')->latest()->first();
        $lastSuccessfulBackupRun = BackupRun::query()
            ->whereNull('backup_group_run_id')
            ->where('status', BackupRun::STATUS_SUCCESS)
            ->orderByDesc('finished_at')
            ->orderByDesc('created_at')
            ->first();
        // Group counterpart of the standalone stat above, aggregated at read time
        // because member archive sizes are recorded asynchronously and can lag the
        // group run's finalization (stays null, never 0, until at least one lands).
        $lastSuccessfulGroupBackupSize = BackupGroupRun::lastSuccessfulTotalBackupSize();
        // The next scheduled backup is whichever comes first: a standalone job
        // (group members are scheduled by their group, not on their own) or a group.
        $nextJob = BackupJob::query()
            ->where('status', BackupJob::STATUS_ACTIVE)
            ->whereNull('backup_job_group_id')
            ->whereNotNull('next_run_at')
            ->orderBy('next_run_at'), $request)
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

        return Inertia::render('Dashboard', [
            'dashboardPreferences' => $preferences,
            'stats' => [
                'total_volumes' => DockerVolume::count(),
                'existing_volumes' => DockerVolume::where('exists', true)->count(),
                'missing_volumes' => DockerVolume::where('exists', false)->count(),
                'backed_up_volumes' => $coverageStats['backed_up_volumes'],
                'configured_volumes' => $coverageStats['configured_volumes'],
                'unprotected_volumes' => $coverageStats['unprotected_volumes'],
                'total_jobs' => BackupJob::whereNull('backup_job_group_id')->count(),
                'active_jobs' => BackupJob::whereNull('backup_job_group_id')->where('status', BackupJob::STATUS_ACTIVE)->count(),
                'paused_jobs' => BackupJob::whereNull('backup_job_group_id')->where('status', BackupJob::STATUS_PAUSED)->count(),
                'error_jobs' => BackupJob::whereNull('backup_job_group_id')->where('status', BackupJob::STATUS_ERROR)->count(),
                'total_groups' => BackupJobGroup::count(),
                'active_groups' => BackupJobGroup::where('status', BackupJobGroup::STATUS_ACTIVE)->count(),
                'paused_groups' => BackupJobGroup::where('status', BackupJobGroup::STATUS_PAUSED)->count(),
                'error_groups' => BackupJobGroup::where('status', BackupJobGroup::STATUS_ERROR)->count(),
                'last_backup_run_status' => $lastBackupRun?->status,
                'last_successful_backup_size' => $lastSuccessfulBackupRun?->backup_size_bytes,
                'last_successful_group_backup_size' => $lastSuccessfulGroupBackupSize,
                'next_scheduled_backup' => $nextScheduledBackup,
            ],
            'recentBackupRuns' => DashboardWidgets::isSectionVisible($preferences, 'recent_backups')
                ? BackupRun::with('job')->whereNull('backup_group_run_id')->latest()->limit(8)->get()
                : [],
            'recentGroupRuns' => DashboardWidgets::isSectionVisible($preferences, 'recent_group_runs')
                ? BackupGroupRun::with('group')->withTotalBackupSize()->latest()->limit(8)->get()
                : [],
            'recentRestoreRuns' => DashboardWidgets::isSectionVisible($preferences, 'recent_restores')
                ? RestoreRun::with('job')->latest()->limit(8)->get()
                : [],
            'jobsWithErrors' => DashboardWidgets::isSectionVisible($preferences, 'jobs_with_errors')
                ? BackupJob::with('destination')
                    ->whereNull('backup_job_group_id')
                    ->where('status', BackupJob::STATUS_ERROR)
                    ->latest()
                    ->limit(8)
                    ->get()
                : [],
            'groupsWithErrors' => DashboardWidgets::isSectionVisible($preferences, 'groups_with_errors')
                ? BackupJobGroup::where('status', BackupJobGroup::STATUS_ERROR)
                    ->latest()
                    ->limit(8)
                    ->get()
                : [],
        ]);
    }
}
