<?php

namespace App\Actions\Backup;

use Illuminate\Support\Collection;

class SelectAuthoritativeDockerLabelBackupContainers
{
    private const PREFIX = 'dev.darkdragon14.volumevault.';

    public function __construct(private readonly ParseDockerLabelBackupDefinitions $parser) {}

    public function handle(array $containers): array
    {
        [$authoritative, $indeterminateErrors, $partialObservations] = $this->authoritativeContainers($containers);
        [$invalidKeys, $replicaErrors] = $this->replicaStateConflicts($authoritative);

        return [$authoritative, $indeterminateErrors, $invalidKeys, $replicaErrors, $partialObservations];
    }

    public function isStoppedStandalone(array $container): bool
    {
        $project = trim((string) ($container['labels']['com.docker.compose.project'] ?? ''));
        $service = trim((string) ($container['labels']['com.docker.compose.service'] ?? ''));

        return $project === '' && $service === '' && ($container['running'] ?? null) === false;
    }

    private function authoritativeContainers(array $containers): array
    {
        $containers = collect($containers)
            ->reject(fn (array $container): bool => strtolower((string) ($container['labels']['com.docker.compose.oneoff'] ?? 'false')) === 'true');
        $indeterminate = $containers->filter(function (array $container): bool {
            $project = trim((string) ($container['labels']['com.docker.compose.project'] ?? ''));
            $service = trim((string) ($container['labels']['com.docker.compose.service'] ?? ''));

            return ($project !== '') !== ($service !== '');
        });
        $errors = $indeterminate
            ->map(fn (array $container): string => ($container['name'] ?: $container['id']).': Compose identity must define both project and service labels.')
            ->values()
            ->all();
        $partialObservations = $indeterminate
            ->map(fn (array $container): array => $this->parser->partialObservation($container))
            ->values()
            ->all();
        $authoritative = $containers
            ->diffKeys($indeterminate)
            ->groupBy(function (array $container): string {
                $project = trim((string) ($container['labels']['com.docker.compose.project'] ?? ''));
                $service = trim((string) ($container['labels']['com.docker.compose.service'] ?? ''));

                return $project !== '' && $service !== ''
                    ? 'compose:'.$project.'/'.$service
                    : 'container:'.($container['id'] ?? $container['name']);
            })
            ->flatMap(function ($replicas): Collection {
                $running = $replicas->where('running', true);
                $candidates = $running->isNotEmpty() ? $running : $replicas;
                $survivors = $candidates
                    ->groupBy(fn (array $container): string => (string) ($container['labels']['com.docker.compose.container-number'] ?? $container['id']))
                    ->map(fn ($slot) => $slot->sortByDesc(fn (array $container): string => ($container['created'] ?? '').':'.($container['id'] ?? ''))->first())
                    ->values();

                if ($survivors->contains(fn (array $container): bool => ! filled($container['labels']['com.docker.compose.config-hash'] ?? null))) {
                    return $survivors;
                }

                $latest = $survivors->sortByDesc(fn (array $container): string => ($container['created'] ?? '').':'.($container['id'] ?? ''))->first();
                $currentHash = $latest['labels']['com.docker.compose.config-hash'] ?? null;

                return $survivors->filter(fn (array $container): bool => ($container['labels']['com.docker.compose.config-hash'] ?? null) === $currentHash);
            })
            ->values()
            ->all();

        return [$authoritative, $errors, $partialObservations];
    }

    private function replicaStateConflicts(array $containers): array
    {
        $invalidKeys = [];
        $errors = [];

        collect($containers)
            ->groupBy(function (array $container): string {
                $project = trim((string) ($container['labels']['com.docker.compose.project'] ?? ''));
                $service = trim((string) ($container['labels']['com.docker.compose.service'] ?? ''));

                return $project !== '' && $service !== '' ? $project.'/'.$service : 'container:'.$container['id'];
            })
            ->each(function ($replicas, string $owner) use (&$invalidKeys, &$errors): void {
                if ($replicas->count() < 2 || str_starts_with($owner, 'container:')) {
                    return;
                }

                $states = $replicas->map(fn (array $container): array => [
                    'enable' => strtolower(trim((string) ($container['labels'][self::PREFIX.'enable'] ?? 'false'))),
                    'names' => $this->parser->inferNames($container)->sort()->values()->all(),
                ]);

                if ($states->map(fn (array $state): string => json_encode($state, JSON_THROW_ON_ERROR))->unique()->count() === 1) {
                    return;
                }

                foreach ($states->flatMap(fn (array $state): array => $state['names'])->unique() as $name) {
                    $invalidKeys[] = hash('sha256', 'docker-label:'.$owner.':'.$name);
                }
                $errors[] = 'Docker label enable state or backup names differ between active replicas for '.$owner.'.';
            });

        return [array_values(array_unique($invalidKeys)), $errors];
    }
}
