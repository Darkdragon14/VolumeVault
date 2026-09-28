<?php

namespace App\Actions\Docker;

use App\Services\Docker\DockerProcess;
use RuntimeException;

class ListDockerLabelBackupContainers
{
    public function __construct(private readonly DockerProcess $dockerProcess) {}

    public function handle(bool $strict = false): array
    {
        $list = $this->dockerProcess->run(['docker', 'ps', '-a', '--format', '{{.ID}}'], 60);

        if (! $list->successful()) {
            throw new RuntimeException($list->combinedOutput() ?: 'Unable to list Docker containers.');
        }

        $ids = preg_split('/\r\n|\r|\n/', trim($list->output), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($ids === []) {
            return [];
        }

        $inspect = $this->dockerProcess->run(['docker', 'inspect', ...$ids], 60);

        if (! $inspect->successful()) {
            throw new RuntimeException($inspect->combinedOutput() ?: 'Unable to inspect running Docker containers.');
        }

        $payload = json_decode($inspect->output, true);

        if (! is_array($payload)) {
            throw new RuntimeException('Docker returned invalid container inspection data.');
        }

        if ($strict && (count($payload) !== count($ids) || collect($payload)->contains(fn ($container): bool => ! is_array($container)
            || empty($container['Id']) || empty($container['Name']) || empty($container['Created'])
            || ! is_array($container['Mounts'] ?? null) || ! is_array($container['Config'] ?? null)
            || ! array_key_exists('Labels', $container['Config'])
            || ($container['Config']['Labels'] !== null && ! is_array($container['Config']['Labels']))
            || collect($container['Mounts'])->contains(fn ($mount): bool => ! is_array($mount) || ! isset($mount['Type'])
                || ($mount['Type'] === 'volume' && (empty($mount['Name']) || empty($mount['Destination']))))
            || ! is_bool(data_get($container, 'State.Running'))))) {
            throw new RuntimeException('Incomplete Docker label inspection.');
        }

        if ($strict && collect($ids)->contains(fn (string $id): bool => collect($payload)->filter(fn (array $container): bool => str_starts_with($container['Id'], $id))->count() !== 1)) {
            throw new RuntimeException('Docker label inspection identities do not match the container inventory.');
        }

        return collect($payload)->filter(fn (mixed $container): bool => is_array($container))->map(function (array $container): array {
            return [
                'id' => $container['Id'] ?? null,
                'name' => ltrim((string) ($container['Name'] ?? ''), '/'),
                'created' => $container['Created'] ?? null,
                'running' => (bool) data_get($container, 'State.Running', false),
                'labels' => (array) data_get($container, 'Config.Labels', []),
                'mounts' => collect($container['Mounts'] ?? [])->filter(fn (mixed $mount): bool => is_array($mount) && ($mount['Type'] ?? null) === 'volume')->map(fn (array $mount): array => [
                    'name' => $mount['Name'] ?? null,
                    'destination' => $mount['Destination'] ?? null,
                ])->filter(fn (array $mount): bool => filled($mount['name']) && filled($mount['destination']))->values()->all(),
            ];
        })->filter(fn (array $container): bool => filled($container['id']))->values()->all();
    }
}
