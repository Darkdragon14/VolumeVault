<?php

namespace App\Actions\Docker;

use App\Services\Docker\DockerProcess;
use RuntimeException;

class ListDockerVolumes
{
    public function __construct(
        private readonly DockerProcess $dockerProcess,
        private readonly InspectDockerVolume $inspectDockerVolume,
    ) {}

    public function handle(): array
    {
        $result = $this->dockerProcess->run(['docker', 'volume', 'ls', '--format', '{{json .}}'], 60);

        if (! $result->successful()) {
            throw new RuntimeException($result->combinedOutput() ?: 'Unable to list Docker volumes.');
        }

        $volumes = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($result->output));

        foreach ($lines ?: [] as $line) {
            if (! filled($line)) {
                continue;
            }

            $summary = json_decode($line, true);
            $name = $summary['Name'] ?? null;

            if (! filled($name)) {
                continue;
            }

            try {
                $volumes[] = $this->inspectDockerVolume->handle($name);
            } catch (RuntimeException) {
                $volumes[] = [
                    'name' => $name,
                    'driver' => $summary['Driver'] ?? null,
                    'mountpoint' => null,
                    'labels' => [],
                    'options' => [],
                ];
            }
        }

        return $volumes;
    }
}
