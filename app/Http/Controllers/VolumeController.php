<?php

namespace App\Http\Controllers;

use App\Actions\Docker\SyncDockerVolumes;
use App\Models\DockerVolume;
use App\Services\Volumes\VolumeBackupSummaries;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class VolumeController extends Controller
{
    public function index(VolumeBackupSummaries $volumeBackupSummaries): Response
    {
        $volumes = DockerVolume::query()
            ->orderByDesc('exists')
            ->orderBy('name')
            ->get();

        return Inertia::render('Volumes/Index', [
            'volumes' => $volumeBackupSummaries->forVolumes($volumes),
        ]);
    }

    public function sync(Request $request, SyncDockerVolumes $syncDockerVolumes)
    {
        try {
            $result = $this->syncHosts($request, $syncDockerVolumes);

            return back()->with('success', "Synced {$result['found']} Docker volumes. {$result['marked_missing']} marked missing. {$result['removed']} removed. {$result['queued_agent_syncs']} agent syncs queued.");
        } catch (Throwable $exception) {
            return back()->with('error', 'Unable to sync Docker volumes: '.str($exception->getMessage())->limit(500)->toString());
        }
    }

    /**
     * @return array{found: int, marked_missing: int, removed: int, affected_jobs: int, queued_agent_syncs: int}
     */
    private function syncHosts(Request $request, SyncDockerVolumes $syncDockerVolumes): array
    {
        $hosts = $this->syncTargetHosts($request);
        $result = [
            'found' => 0,
            'marked_missing' => 0,
            'removed' => 0,
            'affected_jobs' => 0,
            'queued_agent_syncs' => 0,
        ];

        foreach ($hosts as $host) {
            if ($host->type === Host::TYPE_LOCAL) {
                $localResult = $syncDockerVolumes->handle($host);
                $result['found'] += $localResult['found'];
                $result['marked_missing'] += $localResult['marked_missing'];
                $result['removed'] += $localResult['removed'];
                $result['affected_jobs'] += $localResult['affected_jobs'];

                continue;
            }

            AgentCommand::create([
                'host_id' => $host->id,
                'type' => AgentCommand::TYPE_SYNC_VOLUMES,
                'status' => AgentCommand::STATUS_PENDING,
                'payload' => [],
            ]);
            $result['queued_agent_syncs']++;
        }

        return $result;
    }

    /**
     * @return list<Host>
     */
    private function syncTargetHosts(Request $request): array
    {
        if ($request->filled('host_id')) {
            $host = Host::query()->findOrFail($request->integer('host_id'));
            $this->authorizeHostAccess($request, $host->id);

            return [$host];
        }

        if ($request->boolean('all_hosts')) {
            $hostIds = $this->accessibleHostIds($request);

            return Host::query()->whereIn('id', $hostIds)->active()->get()->all();
        }

        return [Host::localHost()];
    }
}
