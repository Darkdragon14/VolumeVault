<?php

namespace App\Services\Agents;

use App\Actions\Backup\MarkMissingVolumeJobs;
use App\Actions\Backup\ReconcileDockerLabelBackupJobs;
use App\Actions\Backup\WithDockerLabelMutationLocks;
use App\Models\BackupJob;
use App\Models\DockerHost;
use App\Models\DockerLabelBackupSetting;
use App\Models\DockerVolume;
use Illuminate\Support\Facades\DB;

class ReceiveAgentInventory
{
    public function __construct(private readonly AgentRegistry $registry, private readonly MarkMissingVolumeJobs $markMissingVolumeJobs) {}

    /** @param array{instance_id: string, sequence: int, volumes: array, containers: array, host_path_allowlist: array} $data */
    public function handle(DockerHost $host, array $data): bool
    {
        $accepted = DB::transaction(function () use ($host, $data): bool {
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

        if ($accepted) {
            DB::transaction(function () use ($host, $data): void {
                $locked = DockerHost::query()->lockForUpdate()->findOrFail($host->id);
                $this->registry->assertCredential($locked, $host->agent_token_hash, $data['instance_id']);
                if ($locked->agent_inventory_sequence !== (int) $data['sequence'] || $locked->agent_instance_id !== $data['instance_id']) {
                    return;
                }

                $settings = DockerLabelBackupSetting::current($locked->id);
                if (! $settings->enabled) {
                    return;
                }

                if (! in_array('docker-labels-v1', $locked->agent_capabilities ?? [], true) || ! ($data['label_inventory']['complete'] ?? false)) {
                    app(WithDockerLabelMutationLocks::class)->handleForJobsOnHost([], [], function ($destinations, $settings): void {
                        if ($settings?->enabled) {
                            $settings->update(['last_synced_at' => now(), 'last_sync_error' => 'A complete docker-labels-v1 inventory is required. Upgrade the agent or wait for a complete inventory.']);
                        }
                    }, dockerHostId: $locked->id);

                    return;
                }

                app(ReconcileDockerLabelBackupJobs::class)->handleOnHost($locked->id, $data['label_inventory']['containers']);
            });
        }

        return $accepted;
    }
}
