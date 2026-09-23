<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RestoreRun;
use App\Services\Agents\OperationalHostScope;
use Illuminate\Http\JsonResponse;

class RestoreRunController extends Controller
{
    public function index(OperationalHostScope $scope): JsonResponse
    {
        return response()->json([
            ...$scope->props(),
            'data' => $scope->query(RestoreRun::class)->with('job.destination', 'destination', 'archiveRelay')->latest()->limit(100)->get()->map($scope->serialize(...)),
        ]);
    }

    public function show(RestoreRun $restoreRun, OperationalHostScope $scope): JsonResponse
    {
        return response()->json(['data' => $scope->serialize($restoreRun->load('job.destination', 'destination', 'archiveRelay'))]);
    }
}
