<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RunFinalization extends Model
{
    public const TYPE_ARCHIVE_METADATA = 'archive_metadata';

    public const TYPE_FINISHED_NOTIFICATION = 'finished_notification';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const MAX_ATTEMPTS = 5;

    protected $fillable = [
        'backup_run_id',
        'restore_run_id',
        'backup_group_run_id',
        'notification_channel_id',
        'type',
        'deduplication_key',
        'status',
        'attempts',
        'available_at',
        'claimed_at',
        'claim_token',
        'enqueued_at',
        'enqueue_token',
        'finished_at',
        'last_error',
        'context',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'claimed_at' => 'datetime',
            'enqueued_at' => 'datetime',
            'finished_at' => 'datetime',
            'context' => 'array',
        ];
    }

    public function backupRun(): BelongsTo
    {
        return $this->belongsTo(BackupRun::class);
    }

    public function restoreRun(): BelongsTo
    {
        return $this->belongsTo(RestoreRun::class);
    }

    public function backupGroupRun(): BelongsTo
    {
        return $this->belongsTo(BackupGroupRun::class);
    }

    public function notificationChannel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class);
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_PROCESSING])
                ->orWhere(function (Builder $query): void {
                    $query->where('status', self::STATUS_FAILED)
                        ->whereNotNull('available_at');
                });
        });
    }
}
