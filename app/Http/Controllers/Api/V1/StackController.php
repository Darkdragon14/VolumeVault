<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Backup\BackupStack;
use App\Http\Controllers\Controller;
use App\Http\Requests\StackBackupRequest;
use Illuminate\Http\JsonResponse;

class StackController extends Controller
{
    public function backup(StackBackupRequest $request, BackupStack $backupStack): JsonResponse
    {
        $summary = $backupStack->handle($request->stackName(), $request->validated(), $request->user());

        return response()->json(['data' => $summary], 202);
    }
}
