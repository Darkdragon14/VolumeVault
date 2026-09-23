<?php

namespace App\Services\Agents;

use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\DockerVolume;
use App\Models\RestoreRun;
use App\Services\Volumes\VolumeBackupSummaries;

class OperationalDashboard
{
    public function data(OperationalHostScope $scope, VolumeBackupSummaries $summaries): array
    {
        $volumes = $scope->query(DockerVolume::class)->get();
        $jobs = $scope->query(BackupJob::class)->whereNull('backup_job_group_id');
        $groups = $scope->query(BackupJobGroup::class);
        $runs = $scope->query(BackupRun::class)->whereNull('backup_group_run_id');
        $groupRuns = $scope->query(BackupGroupRun::class);
        $stats = [
            'total_volumes' => $volumes->count(),
            'existing_volumes' => $volumes->where('exists', true)->count(),
            'missing_volumes' => $volumes->where('exists', false)->count(),
            ...$summaries->coverageStats($summaries->forVolumes($volumes, $scope)),
            'total_jobs' => (clone $jobs)->count(),
            'total_groups' => (clone $groups)->count(),
            'last_backup_run_status' => (clone $runs)->latest()->first()?->status,
            'last_successful_backup_size' => (clone $runs)->where('status', BackupRun::STATUS_SUCCESS)->orderByDesc('finished_at')->orderByDesc('created_at')->first()?->backup_size_bytes,
            'last_successful_group_backup_size' => (clone $groupRuns)->where('status', BackupGroupRun::STATUS_SUCCESS)->withTotalBackupSize()->orderByDesc('finished_at')->orderByDesc('created_at')->first()?->total_backup_size_bytes,
            'next_scheduled_backup' => collect([
                (clone $jobs)->where('status', BackupJob::STATUS_ACTIVE)->whereNotNull('next_run_at')->orderBy('next_run_at')->first(['next_run_at'])?->next_run_at,
                (clone $groups)->where('status', BackupJobGroup::STATUS_ACTIVE)->whereNotNull('next_run_at')->orderBy('next_run_at')->first(['next_run_at'])?->next_run_at,
            ])->filter()->sortBy(fn ($date) => $date->getTimestamp())->first(),
        ];
        foreach (['active', 'paused', 'error'] as $status) {
            $stats[$status.'_jobs'] = (clone $jobs)->where('status', $status)->count();
            $stats[$status.'_groups'] = (clone $groups)->where('status', $status)->count();
        }

        return [
            ...$scope->props(),
            'stats' => $stats,
            'recent_backup_runs' => (clone $runs)->with('job')->latest()->limit(8)->get()->map($scope->serialize(...)),
            'recent_group_runs' => (clone $groupRuns)->with('group')->withTotalBackupSize()->latest()->limit(8)->get(),
            'recent_restore_runs' => $scope->query(RestoreRun::class)->with('job')->latest()->limit(8)->get()->map($scope->serialize(...)),
            'jobs_with_errors' => (clone $jobs)->with('destination')->where('status', BackupJob::STATUS_ERROR)->latest()->limit(8)->get()->map(fn (BackupJob $job): array => [...$scope->serialize($job), 'destination' => $job->destination?->safeForFrontend()]),
            'groups_with_errors' => (clone $groups)->where('status', BackupJobGroup::STATUS_ERROR)->latest()->limit(8)->get(),
        ];
    }
}
