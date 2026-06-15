<?php

namespace App\Http\Controllers;

use App\Models\RestoreRun;
use Inertia\Inertia;
use Inertia\Response;

class RestoreRunController extends Controller
{
    public function show(RestoreRun $restoreRun): Response
    {
        return Inertia::render('RestoreRuns/Show', [
            'run' => $restoreRun->load('job.destination', 'destination', 'initiatedBy:id,name,email', 'preRestoreBackup:id,status,backup_key,backup_size_bytes'),
        ]);
    }
}
