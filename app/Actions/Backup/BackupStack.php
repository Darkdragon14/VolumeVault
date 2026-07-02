<?php

namespace App\Actions\Backup;

use App\Jobs\RunBackupJob;
use App\Models\ActivityLog;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerVolume;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Services\Scheduling\BackupScheduleCalculator;
use App\Services\Volumes\VolumeBackupSummaries;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class BackupStack
{
    public function __construct(
        private readonly BackupScheduleCalculator $scheduleCalculator,
        private readonly CreateBackupRun $createBackupRun,
        private readonly VolumeBackupSummaries $summaries,
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
        $volumeNames = DockerVolume::query()
            ->where('exists', true)
            ->get()
            ->filter(fn (DockerVolume $volume): bool => $this->summaries->stackName($volume) === $stackName)
            ->pluck('name');

        if ($volumeNames->isEmpty()) {
            throw ValidationException::withMessages([
                'stack' => 'No Docker volumes found for this stack.',
            ]);
        }

        $created = $this->createMissingJobs($volumeNames, $input);
        $result = $this->queueRuns($volumeNames, $initiatedBy);

        return [
            'created' => $created,
            'queued' => $result['queued'],
            'skipped' => $result['skipped'],
            'grouped' => $result['grouped'],
        ];
    }

    /**
     * Create a default backup job for every volume in the stack that doesn't
     * have one yet. Requires a destination and a valid schedule.
     *
     * @param  Collection<int, string>  $volumeNames
     * @param  array<string, mixed>  $input
     */
    private function createMissingJobs(Collection $volumeNames, array $input): int
    {
        $covered = BackupJob::query()
            ->where('source_type', BackupJob::SOURCE_TYPE_DOCKER_VOLUME)
            ->whereIn('volume_name', $volumeNames->all())
            ->pluck('volume_name');

        $missing = $volumeNames->diff($covered)->values();

        if ($missing->isEmpty()) {
            return 0;
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

        $defaultChannelId = NotificationChannel::where('is_default', true)->orderBy('id')->value('id');

        $missing->each(function (string $volumeName) use ($input, $scheduleType, $scheduleConfig, $timezone, $defaultChannelId): void {
            $job = BackupJob::create([
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

            if ($defaultChannelId) {
                $job->notificationChannels()->sync([(int) $defaultChannelId]);
            }

            ActivityLog::record('backup_job_created', 'Backup job created.', $job);
        });

        return $missing->count();
    }

    /**
     * Queue a manual run for every Docker-volume job in the stack. Jobs that
     * can't run right now (inactive, already running, missing volume…) are
     * skipped individually so one bad job never aborts the batch.
     *
     * @param  Collection<int, string>  $volumeNames
     * @return array{queued: int, skipped: int, grouped: int}
     */
    private function queueRuns(Collection $volumeNames, ?User $initiatedBy): array
    {
        $jobs = BackupJob::query()
            ->where('source_type', BackupJob::SOURCE_TYPE_DOCKER_VOLUME)
            ->whereIn('volume_name', $volumeNames->all())
            ->get();

        $queued = 0;
        $skipped = 0;
        $grouped = 0;

        foreach ($jobs as $job) {
            // A grouped volume is owned by its group (its own schedule and
            // aggregated notifications). A stack backup only queues runs for the
            // selected stack, so it must not run the member individually nor
            // trigger the whole group (which may span other stacks). Report it as
            // grouped — covered elsewhere, not a failed/unavailable skip.
            if ($job->isGroupMember()) {
                $grouped++;

                continue;
            }

            try {
                $run = $this->createBackupRun->handle($job, BackupRun::TRIGGER_MANUAL, $initiatedBy);
                RunBackupJob::dispatch($run->id);
                $queued++;
            } catch (ValidationException) {
                $skipped++;
            }
        }

        return ['queued' => $queued, 'skipped' => $skipped, 'grouped' => $grouped];
    }
}
