<?php

namespace App\Http\Controllers;

use App\Models\BackupGroupRun;
use App\Models\BackupRun;
use Inertia\Inertia;
use Inertia\Response;

class BackupGroupRunController extends Controller
{
    public function show(BackupGroupRun $backupGroupRun): Response
    {
        $backupGroupRun->load('group', 'initiatedBy:id,name,email');
        $backupGroupRun->loadTotalBackupSize();

        return Inertia::render('BackupGroups/RunShow', [
            'run' => [
                ...$backupGroupRun->toArray(),
                'group' => $backupGroupRun->group ? [
                    'id' => $backupGroupRun->group->id,
                    'name' => $backupGroupRun->group->name,
                ] : null,
                'members' => $backupGroupRun->memberRuns()
                    ->with('job:id,name,volume_name,host_path,source_type')
                    ->latest()
                    ->get()
                    ->map(fn (BackupRun $run): array => [
                        'id' => $run->id,
                        'status' => $run->status,
                        'job_name' => $run->job?->name,
                        'source_label' => $run->job?->sourceName(),
                        'started_at' => $run->started_at,
                        'finished_at' => $run->finished_at,
                        'duration_seconds' => $run->duration_seconds,
                        'backup_size_bytes' => $run->backup_size_bytes,
                        'error_message' => $run->error_message,
                    ])->values()->all(),
            ],
        ]);
    }
}
