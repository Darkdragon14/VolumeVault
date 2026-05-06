<?php

namespace App\Http\Controllers;

use App\Models\BackupRun;
use Inertia\Inertia;
use Inertia\Response;

class BackupRunController extends Controller
{
    public function show(BackupRun $backupRun): Response
    {
        return Inertia::render('BackupRuns/Show', [
            'run' => $backupRun->load('job.destination'),
        ]);
    }
}
