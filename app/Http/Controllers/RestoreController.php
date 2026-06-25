<?php

namespace App\Http\Controllers;

use App\Actions\Restore\CreateRestoreRun;
use App\Actions\Restore\GenerateRestoreVolumeName;
use App\Http\Requests\StoreRestoreRequest;
use App\Jobs\RunRestoreJob;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Services\BackupDestinations\ListBackupObjects;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class RestoreController extends Controller
{
    public function create(Request $request, BackupJob $backupJob, ListBackupObjects $listBackupObjects, GenerateRestoreVolumeName $generateRestoreVolumeName): Response
    {
        $backupJob->load('destination');
        $listError = null;

        try {
            $backups = $listBackupObjects->handle($backupJob->destination);
        } catch (Throwable $exception) {
            $backups = [];
            $listError = str($exception->getMessage())->limit(500)->toString();
        }

        $backups = $this->flagBackupsForJob($backups, $backupJob);

        return Inertia::render('Restore/Create', [
            'job' => [
                ...$backupJob->toArray(),
                'destination' => $backupJob->destination?->safeForFrontend(),
                'is_docker_volume_source' => $backupJob->isDockerVolumeSource(),
            ],
            'backups' => $backups,
            // Whether some objects in the destination don't belong to this job, so
            // the wizard knows to offer a "show all backups" escape hatch.
            'hasOtherBackups' => collect($backups)->contains(fn (array $object) => ! ($object['belongs_to_job'] ?? false)),
            'preselectedBackupKey' => $request->query('backup'),
            'listError' => $listError,
            'generatedTargetVolumeName' => $generateRestoreVolumeName->handle($backupJob->sourceName()),
        ]);
    }

    /**
     * Tag each destination object with whether it belongs to this backup job.
     *
     * A destination can hold backups from several jobs; the wizard defaults to
     * this job's own archives. Matching uses the `backup_key` recorded on the
     * job's successful runs (mirrors RunBackup::matchesExpectedBackupObject),
     * which is more reliable than reconstructing the filename template.
     *
     * @param  array<int, array<string, mixed>>  $backups
     * @return array<int, array<string, mixed>>
     */
    private function flagBackupsForJob(array $backups, BackupJob $backupJob): array
    {
        $jobKeys = BackupRun::query()
            ->where('backup_job_id', $backupJob->id)
            ->where('status', BackupRun::STATUS_SUCCESS)
            ->whereNotNull('backup_key')
            ->pluck('backup_key')
            ->filter()
            ->unique()
            ->values();

        return collect($backups)->map(function (array $object) use ($jobKeys): array {
            $object['belongs_to_job'] = $jobKeys->contains(function (string $key) use ($object): bool {
                foreach (['key', 'display_name'] as $field) {
                    $value = (string) ($object[$field] ?? '');

                    if ($value !== '' && ($value === $key || str_ends_with($value, '/'.$key) || str_ends_with($key, '/'.$value))) {
                        return true;
                    }
                }

                return false;
            });

            return $object;
        })->all();
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
        $run = $createRestoreRun->handle($backupJob, $request->validated(), $request->user());
        RunRestoreJob::dispatch($run->id);

        return redirect()->route('restore-runs.show', $run)->with('success', 'Restore run queued.');
    }
}
