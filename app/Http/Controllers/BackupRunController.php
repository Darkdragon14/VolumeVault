<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesHostAccess;
use App\Models\BackupRun;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BackupRunController extends Controller
{
    use AuthorizesHostAccess;

    public function show(Request $request, BackupRun $backupRun): Response
    {
        $this->authorizeHostAccess($request, $backupRun->host_id);

        return Inertia::render('BackupRuns/Show', [
            'run' => $backupRun->load('job.destination', 'initiatedBy:id,name,email'),
        ]);
    }
}
