<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'host_id',
        'backup_job_id',
        'backup_group_run_id',
        'initiated_by_user_id',
        'status',
        'trigger',
        'started_at',
        'last_heartbeat_at',
        'finished_at',
        'duration_seconds',
        'logs',
        'error_message',
        'docker_container_id',
        'stopped_container_ids',
        'backup_key',
        'backup_size_bytes',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_seconds' => 'integer',
            'stopped_container_ids' => 'array',
            'backup_size_bytes' => 'integer',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(BackupJob::class, 'backup_job_id');
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

    /**
     * A run that still matters for crash recovery: queued/running, or terminal but
     * still owning containers it stopped and has not restarted yet (a worker can
     * mark a run SUCCESS then crash in its finally before restarting them).
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
                    ->where('stopped_container_ids', '!=', '[]'));
        });
    }
}
