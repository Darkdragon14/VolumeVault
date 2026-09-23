<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\BackupDestination;
use App\Models\DockerHost;
use App\Services\BackupDestinations\DestinationStorage;
use App\Services\Docker\LocalDockerExecution;
use App\Services\InstallationSaves\CreateSecureInstallationSave;
use App\Support\DeploymentMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class InstallationSaveController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('InstallationSaves/Index', [
            'destinations' => $this->availableDestinations()
                ->orderBy('name')->get()->map->safeForFrontend(),
        ]);
    }

    public function download(CreateSecureInstallationSave $createSecureInstallationSave)
    {
        try {
            $save = $createSecureInstallationSave->handle();
        } catch (Throwable $exception) {
            return back()->with('error', str($exception->getMessage())->limit(500)->toString());
        }

        ActivityLog::record('installation_save_downloaded', 'Secure installation save downloaded.', null, [
            'filename' => $save->filename,
            'size' => $save->size,
        ]);

        return response()->download($save->path, $save->filename, [
            'Content-Type' => 'application/octet-stream',
        ])->deleteFileAfterSend(true);
    }

    public function upload(Request $request, CreateSecureInstallationSave $createSecureInstallationSave, DestinationStorage $storage)
    {
        $data = $request->validate([
            'backup_destination_id' => ['required', 'integer', Rule::exists('backup_destinations', 'id')],
        ]);

        $destination = $this->availableDestinations()->findOrFail($data['backup_destination_id']);

        if ($destination->isHostBound()) {
            LocalDockerExecution::validate();
        }

        try {
            $save = $createSecureInstallationSave->handle();
            $key = $storage->upload($destination, $save->path, $save->filename, 'installation-saves');

            ActivityLog::record('installation_save_uploaded', 'Secure installation save uploaded to backup destination.', $destination, [
                'filename' => $save->filename,
                'size' => $save->size,
                'key' => $key,
            ]);

            return back()->with('success', 'Secure installation save uploaded to '.$key.'.');
        } catch (Throwable $exception) {
            return back()->with('error', str($exception->getMessage())->limit(500)->toString());
        } finally {
            if (isset($save)) {
                File::delete($save->path);
            }
        }
    }

    private function availableDestinations(): Builder
    {
        return BackupDestination::where('is_active', true)
            ->where(function (Builder $query): void {
                $query->whereNotIn('provider', [BackupDestination::PROVIDER_LOCAL, BackupDestination::PROVIDER_DOCKER_VOLUME]);

                if (DeploymentMode::localExecutionEnabled()) {
                    $query->orWhere('docker_host_id', DockerHost::LOCAL_ID);
                }
            });
    }
}
