<?php

namespace App\Models;

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
        'backup_job_id',
        'initiated_by_user_id',
        'status',
        'trigger',
        'started_at',
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

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }
}
