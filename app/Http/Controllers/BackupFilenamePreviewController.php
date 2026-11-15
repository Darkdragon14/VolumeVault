<?php

namespace App\Http\Controllers;

use App\Actions\Backup\RenderBackupFilename;
use App\Http\Requests\BackupFilenamePreviewRequest;
use Illuminate\Http\JsonResponse;

class BackupFilenamePreviewController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(BackupFilenamePreviewRequest $request, RenderBackupFilename $renderer): JsonResponse
    {
        return response()->json(['filename' => $renderer->finalize($request->validated('stem'))]);
    }
}
