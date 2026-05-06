<?php

namespace App\Actions\Docker;

use App\Services\Docker\DockerProcess;
use RuntimeException;

class FindContainersUsingVolume
{
    public function __construct(private readonly DockerProcess $dockerProcess) {}

    public function handle(string $volumeName): array
    {
        $result = $this->dockerProcess->run(['docker', 'ps', '-a', '--filter', "volume={$volumeName}", '--format', '{{json .}}'], 60);

        if (! $result->successful()) {
            throw new RuntimeException($result->combinedOutput() ?: "Unable to find containers using {$volumeName}.");
        }

        $containers = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($result->output));

        foreach ($lines ?: [] as $line) {
            if (! filled($line)) {
                continue;
            }

            $payload = json_decode($line, true);

            if (! is_array($payload)) {
                continue;
            }

            $containers[] = [
                'id' => $payload['ID'] ?? $payload['Id'] ?? null,
                'names' => $payload['Names'] ?? null,
                'image' => $payload['Image'] ?? null,
                'state' => $payload['State'] ?? null,
                'status' => $payload['Status'] ?? null,
            ];
        }

        return array_values(array_filter($containers, fn (array $container) => filled($container['id'])));
    }
}
