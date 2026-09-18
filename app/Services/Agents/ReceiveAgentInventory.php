<?php

namespace App\Services\Agents;

use App\Actions\Backup\MarkMissingVolumeJobs;
use App\Models\BackupJob;
use App\Models\DockerHost;
use App\Models\DockerVolume;
use Illuminate\Support\Facades\DB;

class ReceiveAgentInventory
{
    public function __construct(private readonly AgentRegistry $registry, private readonly MarkMissingVolumeJobs $markMissingVolumeJobs) {}

    /** @param array{instance_id: string, sequence: int, volumes: array, containers: array, host_path_allowlist: array} $data */
    public function handle(DockerHost $host, array $data): bool
    {
        return DB::transaction(function () use ($host, $data): bool {
            $locked = DockerHost::query()->lockForUpdate()->find($host->id);
            $this->registry->assertCredential($locked, $host->agent_token_hash, $data['instance_id']);
            if ($data['sequence'] <= $locked->agent_inventory_sequence) {
                return false;
            }

            $names = [];
            foreach ($data['volumes'] as $volume) {
                $names[] = $volume['name'];
                DockerVolume::updateOrCreate(['docker_host_id' => $locked->id, 'name' => $volume['name']], [
                    'driver' => $volume['driver'] ?? null,
                    'mountpoint' => $volume['mountpoint'] ?? null,
                    'labels' => $volume['labels'] ?? [], 'options' => $volume['options'] ?? [],
                    'exists' => true, 'last_seen_at' => now(),
                ]);
            }

            $missingNames = BackupJob::where('docker_host_id', $locked->id)
                ->where('source_type', BackupJob::SOURCE_TYPE_DOCKER_VOLUME)
                ->whereNotNull('volume_name')->whereNotIn('volume_name', $names)->pluck('volume_name')->unique()->values();
            foreach ($missingNames as $name) {
                DockerVolume::firstOrCreate(['docker_host_id' => $locked->id, 'name' => $name], ['exists' => false]);
            }
            DockerVolume::where('docker_host_id', $locked->id)->whereNotIn('name', $names)->update(['exists' => false]);
            $this->markMissingVolumeJobs->handle($missingNames->all(), $locked->id);
            $locked->forceFill([
                'agent_inventory_sequence' => $data['sequence'], 'last_inventory_at' => now(),
                'last_seen_at' => now(), 'docker_status' => 'ready',
                'docker_version' => $data['docker_version'] ?? null,
                'docker_container_count' => count($data['containers']),
                'agent_containers' => $data['containers'],
                'agent_host_path_allowlist' => $data['host_path_allowlist'],
            ])->save();

            return true;
        });
    }
}
