<?php

namespace App\Http\Controllers;

use App\Actions\Docker\SyncDockerVolumes;
use App\Models\BackupJob;
use App\Models\DockerVolume;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class VolumeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Volumes/Index', [
            'volumes' => DockerVolume::query()
                ->orderByDesc('exists')
                ->orderBy('name')
                ->get()
                ->map(fn (DockerVolume $volume) => [
                    ...$volume->toArray(),
                    'related_jobs_count' => BackupJob::where('volume_name', $volume->name)->count(),
                ]),
        ]);
    }

    public function sync(SyncDockerVolumes $syncDockerVolumes)
    {
        try {
            $result = $syncDockerVolumes->handle();

            return back()->with('success', "Synced {$result['found']} Docker volumes. {$result['marked_missing']} marked missing.");
        } catch (Throwable $exception) {
            return back()->with('error', 'Unable to sync Docker volumes: '.str($exception->getMessage())->limit(500)->toString());
        }
    }
}
