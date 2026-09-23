<?php

namespace App\Actions\Docker;

use App\Services\Docker\DockerProcess;
use RuntimeException;

class ReadDockerHostInfo
{
    public function __construct(private readonly DockerProcess $dockerProcess) {}

    /** @return array{version: string, containers: int} */
    public function handle(): array
    {
        $result = $this->dockerProcess->run([
            'docker', 'info', '--format', '{"version":{{json .ServerVersion}},"containers":{{json .Containers}}}',
        ], 10);
        if (! $result->successful()) {
            throw new RuntimeException('Docker host information is unavailable.');
        }
        $info = json_decode($result->output, true);
        if (! is_array($info) || ! is_string($info['version'] ?? null)
            || trim($info['version']) === '' || strlen($info['version']) > 100
            || ! is_int($info['containers'] ?? null) || $info['containers'] < 0) {
            throw new RuntimeException('Invalid Docker host information.');
        }

        return ['version' => trim($info['version']), 'containers' => $info['containers']];
    }
}
