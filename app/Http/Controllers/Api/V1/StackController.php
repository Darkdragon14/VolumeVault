<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Backup\BackupStack;
use App\Http\Controllers\Controller;
use App\Http\Requests\StackBackupRequest;
use App\Models\DockerVolume;
use App\Services\Agents\OperationalHostScope;
use App\Services\Volumes\VolumeBackupSummaries;
use Illuminate\Http\JsonResponse;

class StackController extends Controller
{
    public function index(OperationalHostScope $scope, VolumeBackupSummaries $summaries): JsonResponse
    {
        return response()->json([
            ...$scope->props(),
            'data' => $summaries->forStacks($scope->query(DockerVolume::class)->orderByDesc('exists')->orderBy('name')->get(), $scope),
        ]);
    }

    public function backup(StackBackupRequest $request, BackupStack $backupStack): JsonResponse
    {
        $summary = $backupStack->handle($request->stackName(), $request->validated(), $request->user());

        return response()->json(['data' => $summary], 202);
    }
}
