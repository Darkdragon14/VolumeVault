<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentOperation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['payload', 'context', 'delivery_token', 'owner_instance_id'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array', 'context' => 'encrypted:array', 'delivery_token' => 'encrypted',
            'claimed_at' => 'datetime', 'last_progress_at' => 'datetime', 'completed_at' => 'datetime',
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
}
