<?php

namespace App\Http\Controllers;

use App\Models\BackupDestination;
use App\Models\BackupRun;
use App\Services\BackupDestinations\ListBackupObjects;
use Inertia\Inertia;
use Inertia\Response;

class BackupRunController extends Controller
{
    public function show(BackupRun $backupRun): Response
    {
        $backupRun->load('job.destination', 'initiatedBy:id,name,email');
        $destination = new BackupDestination([
            'provider' => $backupRun->backup_destination_provider ?? $backupRun->destinationForRun()?->provider,
        ]);
        $run = $backupRun->toArray();
        $run['restore_unverifiable'] = $backupRun->status === BackupRun::STATUS_SUCCESS
            && ListBackupObjects::isRunUnverifiable($destination, $backupRun);

        return Inertia::render('BackupRuns/Show', [
            'run' => $run,
        ]);
    }
}
