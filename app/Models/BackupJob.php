<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BackupJob extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_ERROR = 'error';

    public const STATUS_RUNNING = 'running';

    public const SCHEDULE_HOURLY = 'hourly';

    public const SCHEDULE_DAILY = 'daily';

    public const SCHEDULE_WEEKLY = 'weekly';

    public const SCHEDULE_CRON = 'cron';

    protected $fillable = [
        'name',
        'volume_name',
        'backup_destination_id',
        'schedule_type',
        'schedule_config',
        'cron_expression',
        'status',
        'pause_reason',
        'last_run_at',
        'next_run_at',
        'last_success_at',
        'last_error',
        'retention_days',
        'retention_count',
        'backup_exclude_regexp',
        'stop_containers_before_backup',
    ];

    protected function casts(): array
    {
        return [
            'schedule_config' => 'array',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
            'last_success_at' => 'datetime',
            'retention_days' => 'integer',
            'retention_count' => 'integer',
            'stop_containers_before_backup' => 'boolean',
        ];
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(BackupDestination::class, 'backup_destination_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(BackupRun::class)->latest();
    }

    public function restoreRuns(): HasMany
    {
        return $this->hasMany(RestoreRun::class)->latest();
    }

    public function notificationChannels(): BelongsToMany
    {
        return $this->belongsToMany(NotificationChannel::class)->withTimestamps();
    }
}
