<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One aggregated execution of a {@see BackupJobGroup}. It fans out to one child
 * {@see BackupRun} per member job, then rolls their outcomes up into a single
 * status and a single finish notification.
 */
class BackupGroupRun extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const TRIGGER_SCHEDULED = 'scheduled';

    public const TRIGGER_MANUAL = 'manual';

    protected $fillable = [
        'backup_job_group_id',
        'initiated_by_user_id',
        'status',
        'trigger',
        'started_at',
        'finished_at',
        'duration_seconds',
        'total_members',
        'succeeded_members',
        'failed_members',
        'error_message',
        'logs',
        'last_heartbeat_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'duration_seconds' => 'integer',
            'total_members' => 'integer',
            'succeeded_members' => 'integer',
            'failed_members' => 'integer',
            'total_backup_size_bytes' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(BackupJobGroup::class, 'backup_job_group_id');
    }

    public function memberRuns(): HasMany
    {
        return $this->hasMany(BackupRun::class, 'backup_group_run_id');
    }

    /**
     * Aggregate the member runs' archive sizes into a `total_backup_size_bytes`
     * attribute. Uses SQL `SUM`, so a run with no member sizes recorded yet stays
     * null (never coerced to 0) — the metadata is written asynchronously shortly
     * after each volume finishes, so it can lag the group run's finalization.
     */
    public function scopeWithTotalBackupSize(Builder $query): Builder
    {
        return $query->withSum('memberRuns as total_backup_size_bytes', 'backup_size_bytes');
    }

    /**
     * Load the aggregated member archive size onto this already-fetched run as a
     * `total_backup_size_bytes` attribute — the instance counterpart of
     * {@see scopeWithTotalBackupSize()}, staying null until member sizes exist.
     */
    public function loadTotalBackupSize(): self
    {
        return $this->loadSum('memberRuns as total_backup_size_bytes', 'backup_size_bytes');
    }

    /**
     * The aggregated archive size of the most recent successful group run — for a
     * given group when $groupId is passed, or across all groups otherwise. Null
     * when none exists yet or its member sizes have not been recorded.
     */
    public static function lastSuccessfulTotalBackupSize(?int $groupId = null): ?int
    {
        return static::query()
            ->when($groupId !== null, fn (Builder $query): Builder => $query->where('backup_job_group_id', $groupId))
            ->where('status', self::STATUS_SUCCESS)
            ->select('id')
            ->withTotalBackupSize()
            ->orderByDesc('finished_at')
            ->orderByDesc('created_at')
            ->first()
            ?->total_backup_size_bytes;
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }
}
