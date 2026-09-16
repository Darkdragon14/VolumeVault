<?php

namespace App\Actions\Backup;

use Illuminate\Support\Collection;
use InvalidArgumentException;

class ParseDockerLabelBackupDefinitions
{
    private const PREFIX = 'dev.darkdragon14.volumevault.';

    private const DEFINITION_PREFIX = self::PREFIX.'backup.';

    private const FIELDS = [
        'volume',
        'mount',
        'destination',
        'schedule',
        'time',
        'day',
        'every-hours',
        'cron',
        'timezone',
        'retention-days',
        'retention-count',
        'filter-mode',
        'include-paths',
        'exclude-regexp',
        'filename-template',
        'notifications',
        'notification-channels',
        'alert-notifications',
        'stop-containers',
    ];

    public function handle(array $container): array
    {
        $labels = $container['labels'] ?? [];
        $enable = $labels[self::PREFIX.'enable'] ?? null;

        if ($enable === null || $enable === '' || strtolower((string) $enable) === 'false') {
            return [];
        }

        if (strtolower((string) $enable) !== 'true') {
            throw new InvalidArgumentException(self::PREFIX.'enable must be true or false.');
        }

        $definitions = [];

        foreach ($labels as $label => $value) {
            if (! str_starts_with($label, self::DEFINITION_PREFIX)) {
                continue;
            }

            $suffix = substr($label, strlen(self::DEFINITION_PREFIX));
            $parts = explode('.', $suffix);
            $name = count($parts) === 1 ? 'default' : array_shift($parts);
            $field = count($parts) === 1 && $name === 'default' ? $parts[0] : implode('.', $parts);

            if ($name === '' || ! preg_match('/^[A-Za-z0-9_-]+$/', $name) || ! in_array($field, self::FIELDS, true)) {
                throw new InvalidArgumentException('Unknown backup label '.$label.'.');
            }

            $definitions[$name][$field] = $value;
        }

        if ($definitions === []) {
            throw new InvalidArgumentException('Enabled containers must define at least one '.self::DEFINITION_PREFIX.'* label.');
        }

        return collect($definitions)->map(fn (array $fields, string $name): array => ['name' => $name, 'fields' => $fields])->values()->all();
    }

    public function inferNames(array $container): Collection
    {
        return collect($container['labels'] ?? [])
            ->keys()
            ->filter(fn (mixed $label): bool => is_string($label) && str_starts_with($label, self::DEFINITION_PREFIX))
            ->map(function (string $label): string {
                $suffix = substr($label, strlen(self::DEFINITION_PREFIX));
                $parts = explode('.', $suffix);

                return count($parts) === 1 ? 'default' : $parts[0];
            })
            ->filter(fn (string $name): bool => $name !== '')
            ->unique()
            ->values();
    }

    public function key(array $container, string $name): string
    {
        $project = trim((string) ($container['labels']['com.docker.compose.project'] ?? ''));
        $service = trim((string) ($container['labels']['com.docker.compose.service'] ?? ''));
        $owner = $project !== '' && $service !== '' ? $project.'/'.$service : (string) $container['name'];

        return hash('sha256', 'docker-label:'.$owner.':'.$name);
    }

    /** @return array{owner_type: string, project: ?string, service: ?string, container: string, definition_name: string} */
    public function origin(array $container, string $name): array
    {
        $project = trim((string) ($container['labels']['com.docker.compose.project'] ?? ''));
        $service = trim((string) ($container['labels']['com.docker.compose.service'] ?? ''));

        return [
            'owner_type' => $project !== '' && $service !== '' ? 'compose' : 'container',
            'project' => $project !== '' ? $project : null,
            'service' => $service !== '' ? $service : null,
            'container' => (string) $container['name'],
            'definition_name' => $name,
        ];
    }

    /** @return array{project: ?string, service: ?string, definition_names: array<int, string>} */
    public function partialObservation(array $container): array
    {
        $project = trim((string) ($container['labels']['com.docker.compose.project'] ?? ''));
        $service = trim((string) ($container['labels']['com.docker.compose.service'] ?? ''));

        return [
            'project' => $project !== '' ? $project : null,
            'service' => $service !== '' ? $service : null,
            'definition_names' => $this->inferNames($container)->all(),
        ];
    }

    public function originMatchesObservation(mixed $origin, array $observation): bool
    {
        if (! is_array($origin) || ($origin['owner_type'] ?? null) !== 'compose') {
            return false;
        }

        $matchesOwner = isset($observation['project'])
            ? ($origin['project'] ?? null) === $observation['project']
            : ($origin['service'] ?? null) === ($observation['service'] ?? null);
        $definitionNames = $observation['definition_names'] ?? [];

        return $matchesOwner
            && ($definitionNames === [] || in_array($origin['definition_name'] ?? null, $definitionNames, true));
    }
}
