<?php

namespace App\Services\Agents;

use App\Models\BackupGroupRun;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Models\RestoreRun;
use App\Support\DeploymentMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class OperationalHostScope
{
    public readonly ?int $hostId;

    private Collection $hosts;

    public function __construct(private readonly Request $request)
    {
        $validated = $request->validate(['docker_host_id' => ['nullable', 'integer', 'exists:docker_hosts,id']]);
        $this->hostId = isset($validated['docker_host_id']) ? (int) $validated['docker_host_id'] : null;
        $this->hosts = DockerHost::withCount(['volumes', 'volumes as existing_volumes_count' => fn (Builder $query) => $query->where('exists', true)])->orderBy('name')->get()->keyBy('id');
    }

    public function apply(Builder $query): Builder
    {
        if ($this->hostId === null) {
            return $query;
        }

        return match (true) {
            $query->getModel() instanceof BackupJobGroup => $query->whereHas('members', fn (Builder $members) => $members->where('docker_host_id', $this->hostId)),
            $query->getModel() instanceof BackupGroupRun => $query->whereHas('memberRuns', fn (Builder $runs) => $runs->where('docker_host_id', $this->hostId)),
            $query->getModel() instanceof RestoreRun => $query->where('target_docker_host_id', $this->hostId),
            default => $query->where($query->getModel()->qualifyColumn('docker_host_id'), $this->hostId),
        };
    }

    public function query(string $model): Builder
    {
        return $this->apply($model::query());
    }

    public function summary(?int $id): ?array
    {
        $host = $this->hosts->get($id);
        if ($host === null) {
            return null;
        }
        $execution = app(AgentExecution::class);
        $reason = match (true) {
            $host->isLocal() && ! DeploymentMode::localExecutionEnabled() => 'local_execution_disabled',
            $host->maintenance_requested_at !== null => 'maintenance',
            ! $execution->supportsHost($host, 'backup-v1') => 'backup_unsupported',
            default => null,
        };
        $canManage = $this->request->user()?->isAdmin() === true
            && (! $this->request->is('api/*') || $this->request->user()->tokenCan('write'));

        return [
            'id' => $host->id,
            'name' => $host->name,
            'is_local' => $host->isLocal(),
            'status' => $host->agentStatus(),
            'availability' => $reason ?? (! $host->isLocal() && $host->agentStatus() !== 'online' ? 'agent_offline' : 'available'),
            'maintenance_requested' => $host->maintenance_requested_at !== null,
            'capabilities' => $host->agent_capabilities ?? [],
            'last_seen_at' => $host->last_seen_at,
            'last_inventory_at' => $host->last_inventory_at,
            'docker_container_count' => $host->docker_container_count,
            'total_volumes' => $host->volumes_count,
            'existing_volumes' => $host->existing_volumes_count,
            'missing_volumes' => $host->volumes_count - $host->existing_volumes_count,
            'inventory_source' => $host->isLocal() ? 'local_snapshot' : 'agent_snapshot',
            'canSync' => $canManage && $host->isLocal() && DeploymentMode::localExecutionEnabled() && $host->maintenance_requested_at === null,
            'canBackup' => $canManage && $reason === null,
            'backup_unavailable_reason' => $canManage ? $reason : 'permission_denied',
        ];
    }

    public function props(): array
    {
        return [
            'hosts' => $this->hosts->keys()->map(fn (int $id): array => $this->summary($id))->values(),
            'filters' => ['docker_host_id' => $this->hostId],
        ];
    }

    public function serialize(Model $model): array
    {
        $data = $model->toArray();
        if ($model instanceof RestoreRun) {
            $data['source_docker_host'] = $this->summary($model->source_docker_host_id);
            $data['target_docker_host'] = $this->summary($model->target_docker_host_id);
        } elseif ($model->docker_host_id !== null) {
            $data['docker_host'] = $this->summary((int) $model->docker_host_id);
        }

        if ($model instanceof BackupRun) {
            $data['source_type'] = $model->sourceType();
            $data['source_name'] = $model->sourceName();
        }

        return $data;
    }
}
