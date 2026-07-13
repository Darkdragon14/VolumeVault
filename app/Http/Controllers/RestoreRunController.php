<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesHostAccess;
use App\Models\RestoreRun;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RestoreRunController extends Controller
{
    use AuthorizesHostAccess;

    public function show(Request $request, RestoreRun $restoreRun): Response
    {
        $this->authorizeHostAccess($request, $restoreRun->host_id);

        return Inertia::render('RestoreRuns/Show', [
            'run' => $restoreRun->load('job.destination', 'destination', 'initiatedBy:id,name,email', 'preRestoreBackup:id,status,backup_key,backup_size_bytes'),
        ]);
    }
}
