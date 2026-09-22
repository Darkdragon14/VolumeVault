<?php

namespace App\Services\Agents;

use App\Models\ActivityLog;
use App\Models\BackupGroupRun;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Models\RestoreRun;
use App\Support\DeploymentMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AgentLifecycle
{
    public function __construct(private readonly AgentCompatibility $compatibility) {}

    public function activeOperations(DockerHost $host): int
    {
        $backups = BackupRun::where('docker_host_id', $host->id)->where(function ($query): void {
            $query->where('status', BackupRun::STATUS_RUNNING)
                ->orWhere('docker_container_cleanup_pending', true)
                ->orWhere(fn ($query) => $query->whereNotNull('stopped_container_ids')->whereJsonLength('stopped_container_ids', '>', 0));
        })->count();
        $restores = RestoreRun::where('target_docker_host_id', $host->id)->where(function ($query): void {
            $query->where('status', RestoreRun::STATUS_RUNNING)
                ->orWhere(fn ($query) => $query->whereNotNull('stopped_container_ids')->whereJsonLength('stopped_container_ids', '>', 0));
        })->count();
        $groups = BackupGroupRun::whereNull('member_run_ids')->where('status', BackupGroupRun::STATUS_RUNNING)->where(function ($query) use ($host): void {
            $query->whereHas('group.members', fn ($jobs) => $jobs->where('docker_host_id', $host->id))
                ->orWhereHas('memberRuns', fn ($runs) => $runs->where('docker_host_id', $host->id));
        })->count();

        $destinations = \App\Models\AgentOperation::where('docker_host_id', $host->id)->where('kind', 'destination')->where('status', 'running')->get()
            ->filter(fn ($operation): bool => ! $host->isLocal() || in_array($operation->payload['destination']['provider'] ?? null, ['local', 'docker_volume'], true))->count();

        return max($backups + $restores + $groups + $destinations, (int) ($host->agent_active_operations ?? 0));
    }

    /** @return array{maintenance_requested: bool, maintenance_ready: bool, active_operations: int} */
    public function state(DockerHost $host): array
    {
        $active = $this->activeOperations($host);
        $acknowledged = $host->isLocal() || ($host->agentStatus() === 'online'
            && $host->maintenance_token !== null
            && $host->agent_maintenance_token === $host->maintenance_token
            && $host->agent_active_operations === 0
            && $this->compatibility->status($host) === 'compatible');

        return [
            'maintenance_requested' => $host->maintenance_requested_at !== null,
            'maintenance_ready' => $host->maintenance_requested_at !== null && $acknowledged && $active === 0,
            'active_operations' => $active,
        ];
    }

    /** @return array{maintenance_requested: bool, maintenance_ready: bool, active_operations: int} */
    public function setMaintenance(DockerHost $host, bool $enabled): array
    {
        return DB::transaction(function () use ($host, $enabled): array {
            $locked = DockerHost::query()->lockForUpdate()->findOrFail($host->id);
            abort_if($locked->isLocal() && DeploymentMode::isOrchestrator(), 422, 'This host has no local executor.');
            abort_if(! $locked->isLocal() && ($locked->agent_registered_at === null || $locked->agent_revoked_at !== null), 409, 'Agent is not registered.');
            if (! $enabled) {
                abort_if($this->activeOperations($locked) > 0, 409, 'Operations or cleanup are still active.');
                abort_unless($locked->isLocal() || ($locked->agentStatus() === 'online' && $this->compatibility->status($locked) === 'compatible'), 409, 'A connected compatible agent is required.');

                if ($locked->maintenance_requested_at !== null) {
                    $this->resetWaitingPublications($locked);
                }
            }

            $locked->forceFill([
                'maintenance_requested_at' => $enabled ? ($locked->maintenance_requested_at ?? now()) : null,
                'maintenance_token' => $enabled ? ($locked->maintenance_token ?? (string) Str::uuid()) : null,
                'agent_maintenance_token' => $enabled ? $locked->agent_maintenance_token : null,
            ])->save();
            ActivityLog::record($enabled ? 'host_maintenance_requested' : 'host_maintenance_resumed', $enabled ? 'Host maintenance requested.' : 'Host maintenance ended.', $locked);

            return $this->state($locked);
        });
    }

    /** Restart delivery budgets, not execution history, while admission still holds the host lock. */
    private function resetWaitingPublications(DockerHost $host): void
    {
        $lease = ['dispatch_token' => null, 'dispatch_attempted_at' => null, 'dispatch_published_at' => null];
        BackupRun::where('docker_host_id', $host->id)->where('status', BackupRun::STATUS_QUEUED)
            ->where(fn ($query) => $query->whereNull('backup_group_run_id')
                ->orWhereHas('groupRun', fn ($query) => $query->whereNotNull('member_run_ids')))
            ->where('trigger', '!=', BackupRun::TRIGGER_PRE_RESTORE)
            ->update($lease);
        RestoreRun::where('target_docker_host_id', $host->id)->where('status', RestoreRun::STATUS_QUEUED)
            ->update($lease);

        // Read membership without acquiring group/job locks after the host lock.
        $groupRunIds = BackupGroupRun::where('status', BackupGroupRun::STATUS_QUEUED)
            ->where(function ($query) use ($host): void {
                $query->whereHas('group.members', fn ($members) => $members->where('docker_host_id', $host->id))
                    ->orWhereHas('memberRuns', fn ($runs) => $runs->where('docker_host_id', $host->id));
            })->pluck('id');
        BackupGroupRun::whereKey($groupRunIds)->where('status', BackupGroupRun::STATUS_QUEUED)->update($lease);
    }

    /** @return array<string, mixed> */
    public function updateGuide(DockerHost $host): array
    {
        $host->refresh();
        abort_if($host->isLocal(), 422, 'Update the orchestrator through its existing deployment.');
        abort_unless($this->state($host)['maintenance_ready'], 409, 'Wait for maintenance acknowledgment and cleanup.');
        $image = (string) config('volumevault.agents.image');

        return [
            'image' => $image,
            'version' => $this->compatibility->targetVersion() ?? (string) config('app.version'),
            'container_name' => 'volumevault-agent-'.substr($host->uuid, 0, 8),
            'volume_name' => 'volumevault-agent-'.$host->uuid,
            'command' => 'docker pull '.escapeshellarg($image),
            'maintenance_ready' => true,
            'uses_existing_identity' => true,
        ];
    }
}
