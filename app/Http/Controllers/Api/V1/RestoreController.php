<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Restore\CreateRestoreRun;
use App\Actions\Runs\DispatchQueuedRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRestoreRequest;
use App\Models\BackupJob;
use Illuminate\Http\JsonResponse;

class RestoreController extends Controller
{
    public function store(StoreRestoreRequest $request, BackupJob $backupJob, CreateRestoreRun $createRestoreRun, DispatchQueuedRun $dispatchQueuedRun): JsonResponse
    {
        $run = $createRestoreRun->handle($backupJob, $request->validated(), $request->user());
        $dispatchQueuedRun->handle($run);

        return response()->json(['data' => $run], 202);
    }
}
