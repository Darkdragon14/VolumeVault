<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Agents\OperationalDashboard;
use App\Services\Agents\OperationalHostScope;
use App\Services\Volumes\VolumeBackupSummaries;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(VolumeBackupSummaries $summaries, OperationalHostScope $scope, OperationalDashboard $dashboard): JsonResponse
    {
        return response()->json(['data' => $dashboard->data($scope, $summaries)]);
    }
}
