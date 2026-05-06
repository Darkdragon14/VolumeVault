<?php

namespace App\Http\Controllers;

use App\Actions\Restore\CreateRestoreRun;
use App\Actions\Restore\GenerateRestoreVolumeName;
use App\Http\Requests\StoreRestoreRequest;
use App\Jobs\RunRestoreJob;
use App\Models\BackupJob;
use App\Services\BackupDestinations\ListBackupObjects;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class RestoreController extends Controller
{
    public function create(BackupJob $backupJob, ListBackupObjects $listBackupObjects, GenerateRestoreVolumeName $generateRestoreVolumeName): Response
    {
        $backupJob->load('destination');
        $listError = null;

        try {
            $backups = $listBackupObjects->handle($backupJob->destination);
        } catch (Throwable $exception) {
            $backups = [];
            $listError = str($exception->getMessage())->limit(500)->toString();
        }

        return Inertia::render('Restore/Create', [
            'job' => [
                ...$backupJob->toArray(),
                'destination' => $backupJob->destination?->safeForFrontend(),
            ],
            'backups' => $backups,
            'listError' => $listError,
            'generatedTargetVolumeName' => $generateRestoreVolumeName->handle($backupJob->volume_name),
        ]);
    }

    public function listBackups(BackupJob $backupJob, ListBackupObjects $listBackupObjects): JsonResponse
    {
        $backupJob->load('destination');

        return response()->json([
            'backups' => $listBackupObjects->handle($backupJob->destination),
        ]);
    }

    public function store(StoreRestoreRequest $request, BackupJob $backupJob, CreateRestoreRun $createRestoreRun)
    {
        $run = $createRestoreRun->handle($backupJob, $request->validated());
        RunRestoreJob::dispatch($run->id);

        return redirect()->route('restore-runs.show', $run)->with('success', 'Restore run queued.');
    }
}
