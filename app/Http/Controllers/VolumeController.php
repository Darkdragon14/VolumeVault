<?php

namespace App\Http\Controllers;

use App\Actions\Docker\SyncDockerVolumes;
use App\Models\DockerHost;
use App\Models\DockerVolume;
use App\Services\Docker\LocalDockerExecution;
use App\Services\Volumes\VolumeBackupSummaries;
use App\Support\DeploymentMode;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class VolumeController extends Controller
{
    public function index(VolumeBackupSummaries $volumeBackupSummaries): Response
    {
        $volumes = DockerVolume::query()
            ->when(DeploymentMode::isOrchestrator(), fn ($query) => $query->whereRaw('1 = 0'))
            ->where('docker_host_id', DockerHost::LOCAL_ID)
            ->orderByDesc('exists')
            ->orderBy('name')
            ->get();

        return Inertia::render('Volumes/Index', [
            'volumes' => $volumeBackupSummaries->forVolumes($volumes),
        ]);
    }

    public function sync(SyncDockerVolumes $syncDockerVolumes)
    {
        LocalDockerExecution::validate();

        try {
            $result = $syncDockerVolumes->handle();

            return back()->with('success', "Synced {$result['found']} Docker volumes. {$result['marked_missing']} marked missing. {$result['removed']} removed.");
        } catch (Throwable $exception) {
            return back()->with('error', 'Unable to sync Docker volumes: '.str($exception->getMessage())->limit(500)->toString());
        }
    }
}
