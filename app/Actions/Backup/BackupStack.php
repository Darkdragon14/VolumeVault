<?php

namespace App\Actions\Backup;

use App\Actions\Runs\DispatchQueuedRun;
use App\Models\ActivityLog;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Models\DockerVolume;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Services\Agents\AgentExecution;
use App\Services\Agents\HostWorkAdmission;
use App\Services\Scheduling\BackupScheduleCalculator;
use App\Services\Volumes\VolumeBackupSummaries;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class BackupStack
{
    public function __construct(
        private readonly BackupScheduleCalculator $scheduleCalculator,
        private readonly CreateBackupRun $createBackupRun,
        private readonly VolumeBackupSummaries $summaries,
        private readonly WithDockerLabelMutationLocks $withLocks,
        private readonly DispatchQueuedRun $dispatchQueuedRun,
    ) {}

    /**
     * Back up a whole stack at once.
     *
     * A backup job is created for every Docker volume in the stack that does
     * not have one yet (using the chosen destination and schedule), then a
     * manual run is queued for every Docker-volume job in the stack — existing
     * and newly created. When the stack is already fully configured no
     * destination/schedule is needed: this is the "run all jobs" path.
     *
     * A null stack name targets the "no stack" group.
     *
     * @param  array<string, mixed>  $input  Validated input: backup_destination_id, schedule_type, schedule_config, timezone.
     * @param  User|null  $initiatedBy  The user who triggered the stack backup, recorded on every queued run.
     * @return array{created: int, queued: int, skipped: int, grouped: int}
     *
     * @throws ValidationException When the stack has no volumes, or a job must
     *                             be created but the destination/schedule is
     *                             missing or invalid.
     */
    public function handle(?string $stackName, array $input, ?User $initiatedBy = null): array
    {
        $dockerHostId = (int) ($input['docker_host_id'] ?? DockerHost::LOCAL_ID);
        $result = Cache::lock('stack-backup-'.$dockerHostId.'-'.hash('sha256', $stackName ?? ''), 30)
            ->block(5, fn (): array => DB::transaction(function () use ($stackName, $input, $initiatedBy, $dockerHostId): array {
                $result = $this->reserveRuns($stackName, $input, $initiatedBy);
                DockerHost::query()->whereKey($dockerHostId)->lockForUpdate()->first();
                app(AgentExecution::class)->validateHost($dockerHostId, 'backup-v1');
                app(HostWorkAdmission::class)->assertAccepting($dockerHostId);

                return $result;
            }, attempts: 3));

        foreach ($result['runs'] as $run) {
            $this->dispatchQueuedRun->handle($run);
        }
        unset($result['runs']);

        return $result;
    }

    private function reserveRuns(?string $stackName, array $input, ?User $initiatedBy): array
    {
        $dockerHostId = (int) ($input['docker_host_id'] ?? DockerHost::LOCAL_ID);
        app(AgentExecution::class)->validateHost($dockerHostId, 'backup-v1');
        app(HostWorkAdmission::class)->assertAccepting($dockerHostId);

        $volumeNames = DockerVolume::query()
            ->where('docker_host_id', $dockerHostId)
            ->where('exists', true)
            ->get()
            ->filter(fn (DockerVolume $volume): bool => $this->summaries->stackName($volume) === $stackName)
            ->pluck('name');

        if ($volumeNames->isEmpty()) {
            throw ValidationException::withMessages([
                'stack' => 'No Docker volumes found for this stack.',
            ]);
        }

        $reservations = $this->createMissingJobs($volumeNames, $input, $dockerHostId, $stackName);
        $result = $this->queueRuns($volumeNames, $reservations['pending_job_ids'], $initiatedBy, $dockerHostId);

        return [
            'created' => $reservations['created'],
            'queued' => $result['queued'],
            'skipped' => $result['skipped'],
            'grouped' => $result['grouped'],
            'runs' => $result['runs'],
        ];
    }

    /**
     * Create a default backup job for every volume in the stack that doesn't
     * have one yet. Requires a destination and a valid schedule.
     *
     * @param  Collection<int, string>  $volumeNames
     * @param  array<string, mixed>  $input
     * @return array{created: int, pending_job_ids: array<int, int>}
     */
    private function createMissingJobs(Collection $volumeNames, array $input, int $dockerHostId, ?string $stackName): array
    {
        $defaultChannelId = NotificationChannel::where('is_default', true)->orderBy('id')->value('id');
        $channelIds = $defaultChannelId ? [(int) $defaultChannelId] : [];
        $destinationIds = empty($input['backup_destination_id']) ? [] : [(int) $input['backup_destination_id']];

        return $this->withLocks->handleOnHost(
            $destinationIds,
            function ($destinations, $settings, $managedJobs, $volumes, $notificationChannels) use ($volumeNames, $input, $channelIds, $dockerHostId, $stackName): array {
                app(AgentExecution::class)->validateHost($dockerHostId, 'backup-v1');
                app(HostWorkAdmission::class)->assertAccepting($dockerHostId);

                if ($volumeNames->contains(fn (string $volumeName): bool => ! $volumes->get($volumeName)?->getAttribute('exists')
                    || $this->summaries->stackName($volumes->get($volumeName)) !== $stackName)) {
                    throw ValidationException::withMessages([
                        'volumes' => 'A selected Docker volume no longer exists.',
                    ]);
                }

                $pendingJobs = $managedJobs->filter(function (BackupJob $job) use ($volumeNames): bool {
                    $pending = $job->pending_label_reconciliation;

                    return is_array($pending)
                        && ($pending['action'] ?? null) === 'apply'
                        && ($pending['payload']['source_type'] ?? BackupJob::SOURCE_TYPE_DOCKER_VOLUME) === BackupJob::SOURCE_TYPE_DOCKER_VOLUME
                        && $volumeNames->contains($pending['payload']['volume_name'] ?? null)
                        && $job->label_reconciliation_error === null;
                });
                $covered = BackupJob::query()
                    ->where('docker_host_id', $dockerHostId)
                    ->reservingDockerVolumes()
                    ->where('source_type', BackupJob::SOURCE_TYPE_DOCKER_VOLUME)
                    ->whereIn('volume_name', $volumeNames->all())
                    ->pluck('volume_name');
                $reserved = $pendingJobs
                    ->pluck('pending_label_reconciliation')
                    ->pluck('payload.volume_name');
                $missing = $volumeNames->diff($covered->merge($reserved)->unique())->values();

                if ($missing->isEmpty()) {
                    return ['created' => 0, 'pending_job_ids' => $pendingJobs->keys()->map(fn ($id): int => (int) $id)->values()->all()];
                }

                if (empty($input['backup_destination_id']) || empty($input['schedule_type'])) {
                    throw ValidationException::withMessages([
                        'backup_destination_id' => 'A destination and schedule are required to back up volumes without a job.',
                    ]);
                }

                $scheduleType = $input['schedule_type'];
                $timezone = $input['timezone'] ?? null;

                try {
                    $scheduleConfig = $this->scheduleCalculator->normalize($scheduleType, $input['schedule_config'] ?? []);
                } catch (InvalidArgumentException $exception) {
                    throw ValidationException::withMessages([
                        'schedule_config' => $exception->getMessage(),
                    ]);
                }

                if (! $destinations->get((int) $input['backup_destination_id'])?->is_active) {
                    throw ValidationException::withMessages([
                        'backup_destination_id' => 'The selected backup destination no longer exists or is inactive.',
                    ]);
                }

                $destination = $destinations->get((int) $input['backup_destination_id']);
                if ($destination->isHostBound() && (int) $destination->docker_host_id !== $dockerHostId) {
                    throw ValidationException::withMessages([
                        'backup_destination_id' => 'The destination belongs to another Docker host.',
                    ]);
                }

                if ($notificationChannels->keys()->map(fn ($id): int => (int) $id)->sort()->values()->all()
                    !== collect($channelIds)->sort()->values()->all()) {
                    throw ValidationException::withMessages([
                        'notification_channel_ids' => 'The default notification channel no longer exists.',
                    ]);
                }

                $missing->each(function (string $volumeName) use ($input, $scheduleType, $scheduleConfig, $timezone, $channelIds, $notificationChannels, $dockerHostId): void {
                    $job = BackupJob::create([
                        'docker_host_id' => $dockerHostId,
                        'name' => $volumeName,
                        'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
                        'volume_name' => $volumeName,
                        'backup_destination_id' => (int) $input['backup_destination_id'],
                        'schedule_type' => $scheduleType,
                        'schedule_config' => $scheduleConfig,
                        'cron_expression' => $this->scheduleCalculator->cronExpression($scheduleType, $scheduleConfig),
                        'timezone' => $timezone,
                        'status' => BackupJob::STATUS_ACTIVE,
                        'next_run_at' => $this->scheduleCalculator->nextRunAt($scheduleType, $scheduleConfig, null, $timezone),
                    ]);

                    $job->notificationChannels()->sync($notificationChannels->only($channelIds)->modelKeys());
                    ActivityLog::record('backup_job_created', 'Backup job created.', $job);
                });

                return [
                    'created' => $missing->count(),
                    'pending_job_ids' => $pendingJobs->keys()->map(fn ($id): int => (int) $id)->values()->all(),
                ];
            },
            $volumeNames->all(),
            $channelIds,
            dockerHostId: $dockerHostId,
        );
    }

    /**
     * Queue a manual run for every Docker-volume job in the stack. Jobs that
     * can't run right now (inactive, already running, missing volume…) are
     * skipped individually so one bad job never aborts the batch.
     *
     * @param  Collection<int, string>  $volumeNames
     * @param  array<int, int>  $pendingJobIds
     * @return array{queued: int, skipped: int, grouped: int, runs: list<BackupRun>}
     */
    private function queueRuns(Collection $volumeNames, array $pendingJobIds, ?User $initiatedBy, int $dockerHostId): array
    {
        $jobs = BackupJob::query()
            ->where('docker_host_id', $dockerHostId)
            ->where('source_type', BackupJob::SOURCE_TYPE_DOCKER_VOLUME)
            ->where(function ($query): void {
                $query->where('configuration_source', '!=', BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL)
                    ->orWhereNull('label_reconciliation_error');
            })
            ->where(function ($query) use ($volumeNames, $pendingJobIds): void {
                $query->whereIn('volume_name', $volumeNames->all())
                    ->orWhereIn('id', $pendingJobIds);
            })
            ->with(['group', 'destination'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $queued = 0;
        $skipped = 0;
        $grouped = 0;
        $runs = [];

        foreach ($jobs as $job) {
            // A grouped volume is owned by its group (its own schedule and
            // aggregated notifications). A stack backup only queues runs for the
            // selected stack, so it never runs the member individually nor triggers
            // the whole group (which may span other stacks).
            if ($job->isGroupMember()) {
                // Only report it as grouped — i.e. it will back up on the group's
                // schedule — when that is actually true: a paused member is excluded
                // from group runs and an inactive group is never dispatched, so
                // those count as skipped rather than a false "handled by group".
                if ($job->status !== BackupJob::STATUS_PAUSED && $job->group?->status === BackupJobGroup::STATUS_ACTIVE) {
                    $grouped++;
                } else {
                    $skipped++;
                }

                continue;
            }

            try {
                $run = $this->createBackupRun->handle($job, BackupRun::TRIGGER_MANUAL, $initiatedBy, $volumeNames->all());
                $runs[] = $run;
                $queued++;
            } catch (ValidationException $exception) {
                if ($dockerHostId !== DockerHost::LOCAL_ID && isset($exception->errors()['destination'])
                    && $job->destination?->is_active && $job->destination->isHostBound()
                    && (int) $job->destination->docker_host_id !== $dockerHostId) {
                    throw ValidationException::withMessages([
                        'backup_destination_id' => 'A stack job has a destination belonging to another Docker host.',
                    ]);
                }

                $skipped++;
            }
        }

        return ['queued' => $queued, 'skipped' => $skipped, 'grouped' => $grouped, 'runs' => $runs];
    }
}
