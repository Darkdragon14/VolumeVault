<?php

namespace App\Http\Controllers;

use App\Actions\Docker\SyncDockerVolumes;
use App\Jobs\SyncDockerVolumesJob;
use App\Models\DockerHost;
use App\Models\DockerVolume;
use App\Services\Agents\HostWorkAdmission;
use App\Services\Agents\OperationalHostScope;
use App\Services\Docker\LocalDockerExecution;
use App\Services\Volumes\VolumeBackupSummaries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class VolumeController extends Controller
{
    public function index(VolumeBackupSummaries $volumeBackupSummaries, OperationalHostScope $scope): Response
    {
        $volumes = $scope->query(DockerVolume::class)
            ->orderByDesc('exists')
            ->orderBy('name')
            ->get();

        return Inertia::render('Volumes/Index', [
            ...$scope->props(),
            'volumes' => $volumeBackupSummaries->forVolumes($volumes, $scope),
        ]);
    }

    public function sync(Request $request, SyncDockerVolumes $syncDockerVolumes): RedirectResponse
    {
        $request->validate(['docker_host_id' => ['required_if:async,true', 'integer', 'in:1'], 'async' => ['sometimes', 'boolean']]);
        LocalDockerExecution::validate();
        app(HostWorkAdmission::class)->assertAccepting(DockerHost::LOCAL_ID);

        try {
            if ($request->boolean('async')) {
                SyncDockerVolumesJob::dispatch();

                return back()->with('success', 'Local Docker volume sync queued.');
            }

            $result = $syncDockerVolumes->handle();

            return back()->with('success', "Synced {$result['found']} Docker volumes. {$result['marked_missing']} marked missing. {$result['removed']} removed.");
        } catch (Throwable $exception) {
            return back()->with('error', 'Unable to sync Docker volumes: '.str($exception->getMessage())->limit(500)->toString());
        }
    }
}
