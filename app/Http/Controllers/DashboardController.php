<?php

namespace App\Http\Controllers;

use App\Services\Agents\OperationalDashboard;
use App\Services\Agents\OperationalHostScope;
use App\Services\Volumes\VolumeBackupSummaries;
use App\Support\DashboardWidgets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, VolumeBackupSummaries $summaries, OperationalHostScope $scope, OperationalDashboard $dashboard): Response
    {
        $preferences = DashboardWidgets::normalize($request->user()->dashboard_preferences);
        $data = $dashboard->data($scope, $summaries);

        return Inertia::render('Dashboard', [
            ...$scope->props(),
            'dashboardPreferences' => $preferences,
            'stats' => $data['stats'],
            'recentBackupRuns' => DashboardWidgets::isSectionVisible($preferences, 'recent_backups') ? $data['recent_backup_runs'] : [],
            'recentGroupRuns' => DashboardWidgets::isSectionVisible($preferences, 'recent_group_runs') ? $data['recent_group_runs'] : [],
            'recentRestoreRuns' => DashboardWidgets::isSectionVisible($preferences, 'recent_restores') ? $data['recent_restore_runs'] : [],
            'jobsWithErrors' => DashboardWidgets::isSectionVisible($preferences, 'jobs_with_errors') ? $data['jobs_with_errors'] : [],
            'groupsWithErrors' => DashboardWidgets::isSectionVisible($preferences, 'groups_with_errors') ? $data['groups_with_errors'] : [],
        ]);
    }
}
