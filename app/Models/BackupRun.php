<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BackupRun extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const TRIGGER_SCHEDULED = 'scheduled';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_PRE_RESTORE = 'pre_restore';

    protected $fillable = [
        'backup_job_id',
        'backup_group_run_id',
        'initiated_by_user_id',
        'status',
        'trigger',
        'scheduled_for',
        'source_type_snapshot',
        'source_volume_name',
        'source_host_path',
        'backup_destination_id_snapshot',
        'backup_destination_name',
        'backup_destination_provider',
        'backup_destination_locator_fingerprint',
        'backup_filename',
        'execution_options_snapshot',
        'started_at',
        'last_heartbeat_at',
        'finished_at',
        'duration_seconds',
        'logs',
        'error_message',
        'docker_container_id',
        'docker_container_cleanup_pending',
        'stopped_container_ids',
        'backup_key',
        'backup_size_bytes',
        'archive_metadata_pending',
    ];

    protected $hidden = [
        'backup_destination_locator_fingerprint',
        'execution_options_snapshot',
        'dispatch_token',
        'dispatch_attempted_at',
        'dispatch_published_at',
    ];

    protected function casts(): array
    {
        return [
            'dispatch_attempted_at' => 'datetime',
            'dispatch_published_at' => 'datetime',
            'scheduled_for' => 'datetime',
            'started_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_seconds' => 'integer',
            'docker_container_cleanup_pending' => 'boolean',
            'stopped_container_ids' => 'array',
            'backup_size_bytes' => 'integer',
            'archive_metadata_pending' => 'boolean',
            'execution_options_snapshot' => 'array',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(BackupJob::class, 'backup_job_id');
    }

    public function snapshotDestination(): BelongsTo
    {
        return $this->belongsTo(BackupDestination::class, 'backup_destination_id_snapshot');
    }

    public function sourceType(): string
    {
        return $this->source_type_snapshot ?: $this->job->sourceType();
    }

    public function sourceName(): string
    {
        if ($this->source_type_snapshot === BackupJob::SOURCE_TYPE_HOST_PATH && $this->source_host_path !== null) {
            return $this->source_host_path;
        }

        if ($this->source_type_snapshot === BackupJob::SOURCE_TYPE_DOCKER_VOLUME && $this->source_volume_name !== null) {
            return $this->source_volume_name;
        }

        return $this->job->sourceName();
    }

    public function sourceVolumeName(): ?string
    {
        if ($this->source_type_snapshot !== null) {
            return $this->source_type_snapshot === BackupJob::SOURCE_TYPE_DOCKER_VOLUME
                ? $this->source_volume_name
                : null;
        }

        return $this->job?->volume_name;
    }

    public function destinationForRun(): ?BackupDestination
    {
        if ($this->backup_destination_id_snapshot !== null) {
            return $this->snapshotDestination;
        }

        return $this->job?->destination;
    }

    public function destinationName(): string
    {
        return $this->backup_destination_name
            ?: $this->destinationForRun()?->name
            ?: 'Unknown';
    }

    public function executionJob(): BackupJob
    {
        $job = clone $this->job;

        if ($this->source_type_snapshot !== null) {
            $job->forceFill([
                'source_type' => $this->source_type_snapshot,
                'volume_name' => $this->source_volume_name,
                'host_path' => $this->source_host_path,
                'backup_destination_id' => $this->backup_destination_id_snapshot,
            ]);
        }

        if ($this->execution_options_snapshot !== null) {
            $job->forceFill($this->execution_options_snapshot);
        }

        $job->setRelation('destination', $this->destinationForRun());

        return $job;
    }

    public function groupRun(): BelongsTo
    {
        return $this->belongsTo(BackupGroupRun::class, 'backup_group_run_id');
    }

    /**
     * Whether this run is a member run driven by a group run. Such runs stay
     * silent (the group emits one aggregated notification for the whole set) and
     * never reschedule their job (the group owns the schedule).
     */
    public function belongsToGroupRun(): bool
    {
        return $this->backup_group_run_id !== null;
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    public function finalizations(): HasMany
    {
        return $this->hasMany(RunFinalization::class);
    }

    /**
     * A run that still matters for crash recovery: queued/running, terminal but
     * still owning containers it stopped, or still awaiting removal of its backup
     * helper (which can contain copied credentials after a worker interruption).
     * Deleting the job/destination behind such a run cascade-drops the row
     * ReconcileStaleRuns needs to restart those containers, so deletion is refused
     * while any exists.
     */
    public function scopeActiveOrHoldingContainers(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereIn('status', [self::STATUS_QUEUED, self::STATUS_RUNNING])
                ->orWhere(fn (Builder $inner) => $inner
                    ->whereNotNull('stopped_container_ids')
                    ->whereJsonLength('stopped_container_ids', '>', 0))
                ->orWhere('docker_container_cleanup_pending', true);
        });
    }

    public function scopeRequiringDestinationConfiguration(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->activeOrHoldingContainers()
            ->orWhereHas('finalizations', fn (Builder $query) => $query
                ->where('type', RunFinalization::TYPE_ARCHIVE_METADATA)
                ->outstanding()));
    }

    public function scopeWithOutstandingFinalizations(Builder $query): Builder
    {
        return $query->whereHas('finalizations', fn (Builder $query) => $query->outstanding());
    }
}
