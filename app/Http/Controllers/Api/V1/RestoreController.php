<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Restore\CreateRestoreRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRestoreRequest;
use App\Jobs\RunRestoreJob;
use App\Models\BackupJob;
use Illuminate\Http\JsonResponse;

class RestoreController extends Controller
{
    public function store(StoreRestoreRequest $request, BackupJob $backupJob, CreateRestoreRun $createRestoreRun): JsonResponse
    {
        $run = $createRestoreRun->handle($backupJob, $request->validated());
        RunRestoreJob::dispatch($run->id);

        return response()->json(['data' => $run], 202);
    }
}
