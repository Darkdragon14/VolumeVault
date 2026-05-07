<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestoreRun extends Model
{
    use HasFactory;

    public const MODE_NEW_VOLUME = 'new_volume';

    public const MODE_INPLACE = 'inplace';

    public const MODE_SAFE_INPLACE = 'safe_inplace';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'backup_job_id',
        'backup_destination_id',
        'selected_backup_key',
        'source_volume_name',
        'target_volume_name',
        'mode',
        'status',
        'affected_containers',
        'confirmation_text',
        'started_at',
        'finished_at',
        'duration_seconds',
        'logs',
        'error_message',
        'docker_container_id',
    ];

    protected function casts(): array
    {
        return [
            'affected_containers' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(BackupJob::class, 'backup_job_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(BackupDestination::class, 'backup_destination_id');
    }
}
