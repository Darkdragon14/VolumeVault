<?php

namespace App\Actions\Docker;

use App\Services\Agents\AgentLabelInventory;
use App\Services\Docker\DockerProcess;
use RuntimeException;

class CollectAgentInventory
{
    public function __construct(private readonly DockerProcess $dockerProcess) {}

    public function available(): bool
    {
        try {
            return $this->dockerProcess->run(['docker', 'info', '--format', '{{.ID}}'], 10)->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{volumes: array, containers: array, docker_version: string} */
    public function handle(callable $progress): array
    {
        // All actions must share this process instance so the monitor also runs
        // during individual inspections, including when Docker produces no output.
        $volumes = new ListDockerVolumes($this->dockerProcess, new InspectDockerVolume($this->dockerProcess));
        $containers = new ListDockerContainers($this->dockerProcess);

        return $this->dockerProcess->whileMonitoring($progress, function () use ($volumes, $containers, $progress): array {
            $inventory = $volumes->handle(strict: true);
            foreach ($inventory as $volume) {
                if (empty($volume['mountpoint']) || empty($volume['driver'])) {
                    throw new RuntimeException('Incomplete Docker inventory.');
                }
            }
            $progress();
            $containerInventory = $containers->handle(strict: true);
            $progress();
            $labelInventory = AgentLabelInventory::INCOMPLETE;
            try {
                $labelContainers = (new ListDockerLabelBackupContainers($this->dockerProcess))->handle(strict: true);
                foreach ($labelContainers as &$container) {
                    $container['labels'] = array_filter($container['labels'], fn ($key): bool => AgentLabelInventory::allowsLabel((string) $key), ARRAY_FILTER_USE_KEY);
                }
                unset($container);
                $labelInventory = ['complete' => true, 'containers' => $labelContainers];
            } catch (RuntimeException) {
                // Label inspection is optional; its failure must not suppress ordinary inventory.
            }
            $progress();
            $info = (new ReadDockerHostInfo($this->dockerProcess))->handle();
            $progress();

            return AgentLabelInventory::bounded(['volumes' => $inventory, 'containers' => $containerInventory, 'docker_version' => $info['version'], 'label_inventory' => $labelInventory]);
        }, intervalSeconds: 1);
    }
}
