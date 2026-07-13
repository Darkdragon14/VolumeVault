<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentCommand extends Model
{
    use HasFactory;

    public const TYPE_SYNC_VOLUMES = 'sync_volumes';

    public const TYPE_BACKUP_RUN = 'backup_run';

    public const TYPE_RESTORE_RUN = 'restore_run';

    public const TYPES = [self::TYPE_SYNC_VOLUMES, self::TYPE_BACKUP_RUN, self::TYPE_RESTORE_RUN];

    public const STATUS_PENDING = 'pending';

    public const STATUS_LEASED = 'leased';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'host_id',
        'type',
        'status',
        'payload',
        'secret_payload',
        'backup_run_id',
        'restore_run_id',
        'lease_until',
        'attempts',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'secret_payload' => 'encrypted:array',
            'lease_until' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(Host::class);
    }

    public function backupRun(): BelongsTo
    {
        return $this->belongsTo(BackupRun::class);
    }

    public function restoreRun(): BelongsTo
    {
        return $this->belongsTo(RestoreRun::class);
    }
}
