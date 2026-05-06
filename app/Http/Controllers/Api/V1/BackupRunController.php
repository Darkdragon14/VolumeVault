<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BackupRun;
use Illuminate\Http\JsonResponse;

class BackupRunController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => BackupRun::with('job.destination')->latest()->limit(100)->get(),
        ]);
    }

    public function show(BackupRun $backupRun): JsonResponse
    {
        return response()->json(['data' => $backupRun->load('job.destination')]);
    }
}
