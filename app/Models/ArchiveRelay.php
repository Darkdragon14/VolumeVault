<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArchiveRelay extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['destination_snapshot'];

    protected $with = ['sourceDockerHost:id,name', 'targetDockerHost:id,name'];

    protected function casts(): array
    {
        return ['destination_snapshot' => 'encrypted:array', 'expires_at' => 'datetime', 'cleaned_at' => 'datetime',
            'source_docker_host_id' => 'integer', 'target_docker_host_id' => 'integer', 'size_bytes' => 'integer',
            'uploaded_bytes' => 'integer', 'downloaded_bytes' => 'integer', 'reserved_bytes' => 'integer'];
    }

    public function restoreRun(): BelongsTo
    {
        return $this->belongsTo(RestoreRun::class);
    }

    public function sourceAgentOperation(): BelongsTo
    {
        return $this->belongsTo(AgentOperation::class);
    }

    public function sourceDockerHost(): BelongsTo
    {
        return $this->belongsTo(DockerHost::class, 'source_docker_host_id');
    }

    public function targetDockerHost(): BelongsTo
    {
        return $this->belongsTo(DockerHost::class, 'target_docker_host_id');
    }
}
