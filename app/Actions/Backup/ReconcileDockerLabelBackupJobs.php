<?php

namespace App\Actions\Backup;

use App\Actions\Docker\ListDockerLabelBackupContainers;
use App\Models\ActivityLog;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Models\DockerLabelBackupSetting;
use App\Models\DockerVolume;
use App\Models\NotificationChannel;
use App\Services\Scheduling\BackupScheduleCalculator;
use App\Support\DeploymentMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

class ReconcileDockerLabelBackupJobs
{
    public function __construct(
        private readonly ListDockerLabelBackupContainers $listContainers,
        private readonly ParseDockerLabelBackupDefinitions $definitionParser,
        private readonly SelectAuthoritativeDockerLabelBackupContainers $containerSelector,
        private readonly BackupScheduleCalculator $scheduleCalculator,
        private readonly ApplyPendingDockerLabelReconciliation $applyPendingReconciliation,
        private readonly WithDockerLabelMutationLocks $withLocks,
    ) {}

    public function handle(): array
    {
        if (DeploymentMode::isOrchestrator()) {
            return ['created' => 0, 'updated' => 0, 'disabled' => 0, 'conflicts' => 0, 'errors' => 0];
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return $this->reconcile();
            } catch (RetryDockerLabelMutation) {
                continue;
            }
        }

        throw new RetryDockerLabelMutation('Docker label settings kept changing concurrently.');
    }

    private function reconcile(): array
    {
        $settings = DockerLabelBackupSetting::current();

        if (! $settings->enabled) {
            $managedReferences = BackupJob::query()
                ->where('docker_host_id', DockerHost::LOCAL_ID)
                ->where('configuration_source', BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL)
                ->with('notificationChannels:id')
                ->get();
            $destinationIds = $managedReferences->pluck('backup_destination_id')
                ->merge($managedReferences->pluck('pending_label_reconciliation.payload.backup_destination_id'))
                ->push($settings->backup_destination_id)
                ->filter()->unique()->all();
            $volumeNames = $managedReferences->pluck('volume_name')
                ->merge($managedReferences->pluck('pending_label_reconciliation.payload.volume_name'))
                ->filter()->unique()->all();
            $notificationChannelIds = $managedReferences->pluck('notificationChannels')->flatten()->pluck('id')
                ->merge($managedReferences->pluck('pending_label_reconciliation.notification_channel_ids')->flatten())
                ->filter()->unique()->all();
            $disabled = $this->withLocks->handle($destinationIds, function ($destinations, ?DockerLabelBackupSetting $lockedSettings) use ($settings): int {
                if ($this->settingsChanged($settings, $lockedSettings)) {
                    throw new RetryDockerLabelMutation('Docker label settings changed before they were locked.');
                }

                if ($lockedSettings?->enabled) {
                    return 0;
                }

                $disabled = $this->disableStaleJobs([], 'Docker label backup automation is disabled.');
                $lockedSettings?->update(['last_synced_at' => now(), 'last_sync_error' => null]);

                return $disabled;
            }, $volumeNames, $notificationChannelIds);

            return ['created' => 0, 'updated' => 0, 'disabled' => $disabled, 'conflicts' => 0, 'errors' => 0];
        }

        $errors = [];
        $definitions = [];
        $invalidKeys = [];
        $invalidMessages = [];

        try {
            $containers = $this->listContainers->handle();
        } catch (Throwable $exception) {
            $message = str($exception->getMessage() ?: 'Unable to inspect Docker labels.')->limit(2000)->toString();
            $settings->update(['last_synced_at' => now(), 'last_sync_error' => $message]);

            return ['created' => 0, 'updated' => 0, 'disabled' => 0, 'conflicts' => 0, 'errors' => 1];
        }

        $managedReferences = BackupJob::query()
            ->where('docker_host_id', DockerHost::LOCAL_ID)
            ->where('configuration_source', BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL)
            ->with('notificationChannels:id')
            ->get();
        $knownConfigurationKeys = $managedReferences->pluck('configuration_key')->filter()->all();
        [$containers, $indeterminateErrors, $replicaInvalidKeys, $replicaErrors, $partialObservations] = $this->containerSelector->handle($containers);
        $errors = [...$errors, ...$indeterminateErrors];
        $preservedKeys = $managedReferences
            ->filter(fn (BackupJob $job): bool => collect($partialObservations)->contains(
                fn (array $observation): bool => $this->definitionParser->originMatchesObservation($job->label_origin, $observation),
            ))
            ->pluck('configuration_key')
            ->filter()
            ->all();
        $invalidKeys = [...$invalidKeys, ...$replicaInvalidKeys];
        $errors = [...$errors, ...$replicaErrors];

        foreach ($containers as $container) {
            if ($this->containerSelector->isStoppedStandalone($container)
                && $this->definitionParser->inferNames($container)->doesntContain(
                    fn (string $name): bool => in_array($this->definitionParser->key($container, $name), $knownConfigurationKeys, true),
                )) {
                continue;
            }

            try {
                $containerDefinitions = $this->definitionParser->handle($container);
            } catch (InvalidArgumentException $exception) {
                $errors[] = ($container['name'] ?: $container['id']).': '.$exception->getMessage();
                $invalidKeys = [
                    ...$invalidKeys,
                    ...$this->definitionParser->inferNames($container)->map(fn (string $name): string => $this->definitionParser->key($container, $name))->all(),
                ];

                continue;
            }

            foreach ($containerDefinitions as $definition) {
                $key = $this->definitionParser->key($container, $definition['name']);

                if ($this->containerSelector->isStoppedStandalone($container) && ! in_array($key, $knownConfigurationKeys, true)) {
                    continue;
                }

                try {
                    $resolved = $this->resolveDefinition($container, $definition, $settings);
                    $definitions[$resolved['key']][] = $resolved;
                } catch (InvalidArgumentException $exception) {
                    $errors[] = ($container['name'] ?: $container['id']).': '.$exception->getMessage();
                    $invalidKeys[] = $key;
                    $invalidMessages[$key] = $exception->getMessage();
                }
            }
        }

        $invalidKeys = array_values(array_unique($invalidKeys));

        $resolvedDefinitions = collect($definitions)->flatten(1);
        $destinationIds = $resolvedDefinitions->pluck('payload.backup_destination_id')
            ->merge($managedReferences->pluck('backup_destination_id'))
            ->merge($managedReferences->pluck('pending_label_reconciliation.payload.backup_destination_id'))
            ->filter()->unique()->all();
        $volumeNames = $resolvedDefinitions->pluck('payload.volume_name')
            ->merge($managedReferences->pluck('volume_name'))
            ->merge($managedReferences->pluck('pending_label_reconciliation.payload.volume_name'))
            ->filter()->unique()->all();
        $notificationChannelIds = $resolvedDefinitions->pluck('notification_channel_ids')->flatten()
            ->merge($managedReferences->pluck('notificationChannels')->flatten()->pluck('id'))
            ->merge($managedReferences->pluck('pending_label_reconciliation.notification_channel_ids')->flatten())
            ->filter()->unique()->all();
        $result = $this->withLocks->handle($destinationIds, function ($destinations, ?DockerLabelBackupSetting $lockedSettings, $jobs, $volumes, $notificationChannels) use ($settings, $definitions, $invalidKeys, $invalidMessages, $preservedKeys, $indeterminateErrors, &$errors): array {
            if ($this->settingsChanged($settings, $lockedSettings)) {
                throw new RetryDockerLabelMutation('Docker label settings changed before they were locked.');
            }

            if (! $lockedSettings?->enabled) {
                $disabled = $this->disableStaleJobs([], 'Docker label backup automation is disabled.');
                $lockedSettings?->update(['last_synced_at' => now(), 'last_sync_error' => null]);

                return ['created' => 0, 'updated' => 0, 'disabled' => $disabled, 'conflicts' => 0];
            }

            $desiredKeys = [...$invalidKeys, ...$preservedKeys];
            $created = 0;
            $updated = 0;
            $disabled = 0;
            $conflicts = count($invalidKeys) + count($indeterminateErrors);

            foreach ($invalidKeys as $key) {
                $this->disableManagedJob($key, $invalidMessages[$key] ?? 'Docker label configuration is invalid or conflicting.');
            }

            foreach ($definitions as $key => $replicas) {
                $desiredKeys[] = $key;

                if (in_array($key, $invalidKeys, true)) {
                    continue;
                }
                $fingerprints = collect($replicas)->map(fn (array $definition): string => json_encode([
                    'payload' => $definition['payload'],
                    'notification_channel_ids' => $definition['notification_channel_ids'],
                    'expected_destination' => $definition['expected_destination'],
                    'expected_notification_channels' => $definition['expected_notification_channels'],
                ], JSON_THROW_ON_ERROR))->unique();

                if ($fingerprints->count() !== 1) {
                    $conflicts++;
                    $message = 'Conflicting Docker label definitions were found for '.$key.'.';
                    $errors[] = $message;
                    $this->disableManagedJob($key, $message);

                    continue;
                }

                $definition = $replicas[0];
                $destination = $destinations->get($definition['payload']['backup_destination_id']);
                $volume = $volumes->get($definition['payload']['volume_name']);
                $expectedChannelIds = collect($definition['notification_channel_ids'])->map(fn ($id): int => (int) $id)->sort()->values();
                $existingChannelIds = $expectedChannelIds->filter(fn (int $id): bool => $notificationChannels->has($id))->values();

                $this->ensureExplicitReferencesStillMatch($definition, $destination, $notificationChannels);

                if (! $destination?->is_active) {
                    $conflicts++;
                    $message = 'A Docker label backup destination no longer exists or is inactive.';
                    $errors[] = $message;
                    $this->disableManagedJob($key, $message);

                    continue;
                }

                if (! $volume?->isAvailable()) {
                    $conflicts++;
                    $message = 'Docker volume '.$definition['payload']['volume_name'].' no longer exists or is unavailable.';
                    $errors[] = $message;
                    $this->disableManagedJob($key, $message);

                    continue;
                }

                if ($existingChannelIds->all() !== $expectedChannelIds->all()) {
                    $conflicts++;
                    $message = 'A Docker label notification channel no longer exists.';
                    $errors[] = $message;
                    $this->disableManagedJob($key, $message);

                    continue;
                }

                $managedJob = $jobs->firstWhere('configuration_key', $key);
                $manualJob = BackupJob::query()
                    ->where('docker_host_id', DockerHost::LOCAL_ID)
                    ->where('configuration_source', BackupJob::CONFIGURATION_SOURCE_MANUAL)
                    ->where('source_type', BackupJob::SOURCE_TYPE_DOCKER_VOLUME)
                    ->where('volume_name', $definition['payload']['volume_name'])
                    ->exists();

                if ($manualJob) {
                    $conflicts++;
                    $message = 'A manual backup job already covers Docker volume '.$definition['payload']['volume_name'].'.';
                    $errors[] = $message;
                    $this->disableManagedJob($key, $message);

                    continue;
                }

                if ($managedJob) {
                    $configurationChanged = collect($definition['payload'])->contains(
                        fn (mixed $value, string $field): bool => $managedJob->getAttribute($field) != $value,
                    );
                    $notificationChannelsChanged = $managedJob->notificationChannels()->pluck('notification_channels.id')->sort()->values()->all()
                        !== collect($definition['notification_channel_ids'])->sort()->values()->all();
                    $scheduleChanged = collect(['schedule_type', 'schedule_config', 'cron_expression', 'timezone'])->contains(
                        fn (string $field): bool => $managedJob->getAttribute($field) != $definition['payload'][$field],
                    );

                    $originChanged = $managedJob->label_origin !== $definition['origin'];

                    if ($configurationChanged || $notificationChannelsChanged || $managedJob->pending_label_reconciliation !== null || $managedJob->label_reconciliation_error !== null) {
                        $cancelledOccurrence = $this->cancelQueuedRuns($managedJob);
                        $nextRunAt = ($scheduleChanged || $managedJob->label_reconciliation_error !== null)
                            ? $definition['next_run_at']
                            : $managedJob->next_run_at;

                        if ($cancelledOccurrence !== null && ($nextRunAt === null || $cancelledOccurrence->isBefore($nextRunAt))) {
                            $nextRunAt = $cancelledOccurrence;
                        }

                        $managedJob->update([
                            'pending_label_reconciliation' => [
                                'action' => 'apply',
                                'payload' => $definition['payload'],
                                'next_run_at' => $nextRunAt?->toIso8601String(),
                                'notification_channel_ids' => $definition['notification_channel_ids'],
                                'expected_destination' => $definition['expected_destination'],
                                'expected_notification_channels' => $definition['expected_notification_channels'],
                            ],
                            'label_origin' => $definition['origin'],
                            'label_reconciliation_error' => null,
                        ]);

                        if (! $this->applyPendingReconciliation->handleLocked($managedJob, $destinations, $volumes, $notificationChannels)) {
                            $errors[] = 'Docker label job '.$managedJob->name.' is running; its configuration update was deferred.';
                        }
                    } else {
                        if ($originChanged) {
                            $managedJob->update(['label_origin' => $definition['origin']]);
                        }

                        $this->syncNotificationChannels($managedJob, $definition['notification_channel_ids']);
                    }
                    $updated++;

                    continue;
                }

                $job = BackupJob::create([
                    ...$definition['payload'],
                    'docker_host_id' => DockerHost::LOCAL_ID,
                    'status' => BackupJob::STATUS_ACTIVE,
                    'next_run_at' => $definition['next_run_at'],
                    'configuration_source' => BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL,
                    'configuration_key' => $key,
                    'label_origin' => $definition['origin'],
                ]);
                $this->syncNotificationChannels($job, $definition['notification_channel_ids']);
                ActivityLog::record('backup_job_created', 'Backup job created from Docker labels.', $job);
                $created++;
            }

            $disabled += $this->disableStaleJobs($desiredKeys, 'Docker label definition is no longer active.');

            $lockedSettings?->update([
                'last_synced_at' => now(),
                'last_sync_error' => $errors === [] ? null : implode("\n", array_values(array_unique($errors))),
            ]);

            return compact('created', 'updated', 'disabled', 'conflicts');
        }, $volumeNames, $notificationChannelIds);

        return [...$result, 'errors' => count(array_unique($errors))];
    }

    private function settingsChanged(DockerLabelBackupSetting $settings, ?DockerLabelBackupSetting $lockedSettings): bool
    {
        return ! $lockedSettings
            || $settings->enabled !== $lockedSettings->enabled
            || (int) $settings->backup_destination_id !== (int) $lockedSettings->backup_destination_id
            || $settings->defaults != $lockedSettings->defaults;
    }

    private function resolveDefinition(array $container, array $definition, DockerLabelBackupSetting $settings): array
    {
        $fields = $definition['fields'];
        $volumeSelector = trim((string) ($fields['volume'] ?? ''));
        $mountSelector = trim((string) ($fields['mount'] ?? ''));

        if (($volumeSelector === '') === ($mountSelector === '')) {
            throw new InvalidArgumentException('Backup '.$definition['name'].' must define exactly one volume or mount label.');
        }

        $mount = collect($container['mounts'] ?? [])->first(fn (array $mount): bool => $volumeSelector !== ''
            ? $mount['name'] === $volumeSelector
            : $mount['destination'] === $mountSelector);

        if (! $mount) {
            throw new InvalidArgumentException('Backup '.$definition['name'].' does not match a named volume mounted by the container.');
        }

        if (! DockerVolume::query()->where('docker_host_id', DockerHost::LOCAL_ID)->where('name', $mount['name'])->where('exists', true)->exists()) {
            throw new InvalidArgumentException('Docker volume not found: '.$mount['name']);
        }

        $defaults = $settings->resolvedDefaults();
        $destinationLabel = $fields['destination'] ?? null;
        $destination = $this->destination($destinationLabel, $settings);
        $scheduleType = trim((string) ($fields['schedule'] ?? $defaults['schedule_type']));
        $scheduleConfig = $this->scheduleConfig($scheduleType, $fields, (array) $defaults['schedule_config']);
        $timezone = $this->nullableString($fields['timezone'] ?? $defaults['timezone']);

        if ($timezone !== null && ! in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('Backup '.$definition['name'].' uses an invalid timezone.');
        }

        $scheduleConfig = $this->scheduleCalculator->normalize($scheduleType, $scheduleConfig);
        $project = trim((string) ($container['labels']['com.docker.compose.project'] ?? ''));
        $service = trim((string) ($container['labels']['com.docker.compose.service'] ?? ''));
        $owner = $project !== '' && $service !== '' ? $project.'/'.$service : (string) $container['name'];
        $displayName = $definition['name'] === 'default'
            ? $owner.' - '.$mount['name']
            : $definition['name'];
        $key = $this->definitionParser->key($container, $definition['name']);
        $filterMode = $fields['filter-mode'] ?? $defaults['backup_filter_mode'];

        if (! in_array($filterMode, [BackupJob::FILTER_MODE_EXCLUDE, BackupJob::FILTER_MODE_INCLUDE], true)) {
            throw new InvalidArgumentException('Backup '.$definition['name'].' uses an invalid filter mode.');
        }

        $includePaths = $this->boundedNullableString($fields['include-paths'] ?? $defaults['backup_include_paths'], 2000, 'include-paths');
        $this->validateIncludePaths($includePaths);
        $filenameTemplate = $this->boundedNullableString($fields['filename-template'] ?? $defaults['backup_filename_template'], 180, 'filename-template');

        if ($message = app(RenderBackupFilename::class)->validationError($filenameTemplate)) {
            throw new InvalidArgumentException($message);
        }

        $payload = [
            'name' => mb_substr($displayName, 0, 255),
            'backup_job_group_id' => null,
            'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
            'volume_name' => $mount['name'],
            'host_path' => null,
            'backup_destination_id' => $destination->id,
            'schedule_type' => $scheduleType,
            'schedule_config' => $scheduleConfig,
            'cron_expression' => $this->scheduleCalculator->cronExpression($scheduleType, $scheduleConfig),
            'timezone' => $timezone,
            'retention_days' => $this->nullablePositiveInteger($fields['retention-days'] ?? $defaults['retention_days'], 'retention-days'),
            'retention_count' => $this->nullablePositiveInteger($fields['retention-count'] ?? $defaults['retention_count'], 'retention-count'),
            'backup_filter_mode' => $filterMode,
            'backup_include_paths' => $includePaths,
            'backup_exclude_regexp' => $this->boundedNullableString($fields['exclude-regexp'] ?? $defaults['backup_exclude_regexp'], 1000, 'exclude-regexp'),
            'backup_filename_template' => $filenameTemplate,
            'notifications_enabled' => $this->boolean($fields['notifications'] ?? $defaults['notifications_enabled'], 'notifications'),
            'alert_notifications_enabled' => $this->boolean($fields['alert-notifications'] ?? $defaults['alert_notifications_enabled'] ?? true, 'alert-notifications'),
            'use_custom_alert_settings' => false,
            'stop_containers_before_backup' => $this->boolean($fields['stop-containers'] ?? $defaults['stop_containers_before_backup'], 'stop-containers'),
            'stop_container_names' => null,
        ];

        [$notificationChannelIds, $expectedNotificationChannels] = $this->notificationChannels(
            $fields['notification-channels'] ?? null,
            $defaults,
        );

        return [
            'key' => $key,
            'origin' => $this->definitionParser->origin($container, $definition['name']),
            'payload' => $payload,
            'next_run_at' => $this->scheduleCalculator->nextRunAt($scheduleType, $scheduleConfig, null, $timezone),
            'notification_channel_ids' => $notificationChannelIds,
            'expected_destination' => filled($destinationLabel)
                ? ['id' => $destination->id, 'name' => $destination->name]
                : null,
            'expected_notification_channels' => $expectedNotificationChannels,
        ];
    }

    private function destination(mixed $label, DockerLabelBackupSetting $settings): BackupDestination
    {
        if (filled($label)) {
            $destinations = BackupDestination::query()->where('name', trim((string) $label))->where('is_active', true)->get();

            if ($destinations->count() !== 1) {
                throw new InvalidArgumentException('The destination label must match exactly one active destination name.');
            }

            return $destinations->first();
        }

        $destination = $settings->destination;

        if (! $destination?->is_active) {
            throw new InvalidArgumentException('Docker label backups require an active default destination.');
        }

        return $destination;
    }

    private function scheduleConfig(string $type, array $fields, array $defaults): array
    {
        return match ($type) {
            BackupJob::SCHEDULE_HOURLY => ['everyHours' => $fields['every-hours'] ?? $defaults['everyHours'] ?? 1],
            BackupJob::SCHEDULE_DAILY => ['time' => $fields['time'] ?? $defaults['time'] ?? '02:00'],
            BackupJob::SCHEDULE_WEEKLY => [
                'dayOfWeek' => $fields['day'] ?? $defaults['dayOfWeek'] ?? 'sunday',
                'time' => $fields['time'] ?? $defaults['time'] ?? '03:00',
            ],
            BackupJob::SCHEDULE_CRON => ['expression' => $fields['cron'] ?? $defaults['expression'] ?? ''],
            default => throw new InvalidArgumentException('Unsupported Docker label backup schedule.'),
        };
    }

    private function notificationChannels(mixed $label, array $defaults): array
    {
        if (! filled($label)) {
            $ids = collect($defaults['notification_channel_ids'] ?? [])->map(fn ($id): int => (int) $id)->unique()->values();

            return [
                NotificationChannel::query()->whereKey($ids->all())->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                [],
            ];
        }

        $names = preg_split('/\s*,\s*/', trim((string) $label), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $channels = NotificationChannel::query()->whereIn('name', $names)->get();

        if ($channels->count() !== count(array_unique($names)) || $channels->groupBy('name')->contains(fn ($group): bool => $group->count() !== 1)) {
            throw new InvalidArgumentException('Notification channel labels must match unique existing channel names.');
        }

        return [
            $channels->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            $channels->map(fn (NotificationChannel $channel): array => [
                'id' => $channel->id,
                'name' => $channel->name,
            ])->values()->all(),
        ];
    }

    private function ensureExplicitReferencesStillMatch(
        array $definition,
        ?BackupDestination $destination,
        Collection $notificationChannels,
    ): void {
        $expectedDestination = $definition['expected_destination'];

        if (is_array($expectedDestination)) {
            $matchingDestinationIds = BackupDestination::query()
                ->where('name', $expectedDestination['name'])
                ->where('is_active', true)
                ->pluck('id');

            if ($matchingDestinationIds->count() !== 1
                || (int) $matchingDestinationIds->first() !== (int) $expectedDestination['id']
                || (int) $destination?->id !== (int) $expectedDestination['id']) {
                throw new RetryDockerLabelMutation('A Docker label destination name became ambiguous before it was locked.');
            }
        }

        $expectedChannels = collect($definition['expected_notification_channels']);

        if ($expectedChannels->isNotEmpty()) {
            $matchingChannels = NotificationChannel::query()
                ->whereIn('name', $expectedChannels->pluck('name')->all())
                ->get()
                ->groupBy('name');

            foreach ($expectedChannels as $expectedChannel) {
                $matches = $matchingChannels->get($expectedChannel['name'], collect());

                if ($matches->count() !== 1
                    || (int) $matches->first()->id !== (int) $expectedChannel['id']
                    || ! $notificationChannels->has((int) $expectedChannel['id'])) {
                    throw new RetryDockerLabelMutation('A Docker label notification channel name became ambiguous before it was locked.');
                }
            }
        }
    }

    private function syncNotificationChannels(BackupJob $job, array $ids): void
    {
        $job->notificationChannels()->sync($ids);
    }

    private function disableManagedJob(string $key, string $message): void
    {
        BackupJob::query()
            ->where('docker_host_id', DockerHost::LOCAL_ID)
            ->where('configuration_source', BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL)
            ->where('configuration_key', $key)
            ->get()
            ->each(fn (BackupJob $job) => $this->queueDisable($job, $message));
    }

    private function disableStaleJobs(array $desiredKeys, string $message): int
    {
        $query = BackupJob::query()->where('docker_host_id', DockerHost::LOCAL_ID)->where('configuration_source', BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL);

        if ($desiredKeys !== []) {
            $query->whereNotIn('configuration_key', $desiredKeys);
        }

        return $query->get()->each(fn (BackupJob $job) => $this->queueDisable($job, $message))->count();
    }

    private function queueDisable(BackupJob $job, string $message): void
    {
        $job = BackupJob::query()->where('docker_host_id', DockerHost::LOCAL_ID)->lockForUpdate()->find($job->id);

        if (! $job) {
            return;
        }

        $this->cancelQueuedRuns($job);
        $attributes = [
            'pending_label_reconciliation' => ['action' => 'disable', 'message' => $message],
            'label_reconciliation_error' => $message,
        ];

        if ($job->status !== BackupJob::STATUS_PAUSED) {
            $attributes['next_run_at'] = null;
        }

        $job->update($attributes);
        $this->applyPendingReconciliation->handleLocked($job, collect(), collect(), collect());
    }

    private function cancelQueuedRuns(BackupJob $job): ?Carbon
    {
        $cancelledOccurrence = BackupRun::query()
            ->where('docker_host_id', DockerHost::LOCAL_ID)
            ->where('backup_job_id', $job->id)
            ->where('status', BackupRun::STATUS_QUEUED)
            ->where('trigger', BackupRun::TRIGGER_SCHEDULED)
            ->oldest('scheduled_for')
            ->first(['scheduled_for', 'created_at']);

        BackupRun::query()
            ->where('docker_host_id', DockerHost::LOCAL_ID)
            ->where('backup_job_id', $job->id)
            ->where('status', BackupRun::STATUS_QUEUED)
            ->update([
                'status' => BackupRun::STATUS_CANCELLED,
                'finished_at' => now(),
                'error_message' => 'Docker label configuration changed before this run started.',
            ]);

        return $cancelledOccurrence === null
            ? null
            : Carbon::parse($cancelledOccurrence->scheduled_for ?? $cancelledOccurrence->created_at);
    }

    private function boolean(mixed $value, string $field): bool
    {
        $boolean = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($boolean === null) {
            throw new InvalidArgumentException($field.' must be true or false.');
        }

        return $boolean;
    }

    private function nullablePositiveInteger(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new InvalidArgumentException($field.' must be a positive integer.');
        }

        return (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function boundedNullableString(mixed $value, int $max, string $field): ?string
    {
        $value = $this->nullableString($value);

        if ($value !== null && mb_strlen($value) > $max) {
            throw new InvalidArgumentException($field.' is too long.');
        }

        return $value;
    }

    private function validateIncludePaths(?string $value): void
    {
        foreach (preg_split('/\s*,\s*/', $value ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: [] as $path) {
            $path = trim($path, '/');
            $segments = explode('/', $path);

            if ($path === '' || mb_strlen($path) > 200 || in_array('.', $segments, true) || in_array('..', $segments, true)) {
                throw new InvalidArgumentException('include-paths contains an invalid relative backup path.');
            }
        }
    }
}
