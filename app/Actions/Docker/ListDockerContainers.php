<?php

namespace App\Actions\Docker;

use App\Services\Docker\DockerProcess;
use RuntimeException;

class ListDockerContainers
{
    public function __construct(private readonly DockerProcess $dockerProcess) {}

    public function handle(bool $strict = false): array
    {
        $result = $this->dockerProcess->run(['docker', 'ps', '-a', '--format', '{{json .}}'], 60);

        if (! $result->successful()) {
            throw new RuntimeException($result->combinedOutput() ?: 'Unable to list Docker containers.');
        }

        $containers = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($result->output));

        foreach ($lines ?: [] as $line) {
            if (! filled($line)) {
                continue;
            }

            $payload = json_decode($line, true);

            if (! is_array($payload)) {
                if ($strict) {
                    throw new RuntimeException('Unable to parse complete Docker container inventory.');
                }

                continue;
            }

            if ($strict && ! filled($payload['ID'] ?? $payload['Id'] ?? null)) {
                throw new RuntimeException('Incomplete Docker container identity.');
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
