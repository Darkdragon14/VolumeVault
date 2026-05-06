<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RestoreRun;
use Illuminate\Http\JsonResponse;

class RestoreRunController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => RestoreRun::with('job.destination', 'destination')->latest()->limit(100)->get(),
        ]);
    }

    public function show(RestoreRun $restoreRun): JsonResponse
    {
        return response()->json(['data' => $restoreRun->load('job.destination', 'destination')]);
    }
}
