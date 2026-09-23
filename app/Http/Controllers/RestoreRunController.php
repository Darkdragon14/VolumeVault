<?php

namespace App\Http\Controllers;

use App\Models\RestoreRun;
use App\Services\Agents\OperationalHostScope;
use Inertia\Inertia;
use Inertia\Response;

class RestoreRunController extends Controller
{
    public function show(RestoreRun $restoreRun, OperationalHostScope $scope): Response
    {
        return Inertia::render('RestoreRuns/Show', [
            'run' => $scope->serialize($restoreRun->load('job.destination', 'destination', 'archiveRelay', 'initiatedBy:id,name,email', 'preRestoreBackup:id,status,backup_key,backup_size_bytes')),
        ]);
    }
}
