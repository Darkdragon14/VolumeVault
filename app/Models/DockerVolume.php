<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DockerVolume extends Model
{
    use HasFactory;

    protected $fillable = [
        'host_id',
        'name',
        'driver',
        'mountpoint',
        'labels',
        'options',
        'exists',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'labels' => 'array',
            'options' => 'array',
            'exists' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function backupJobs(): HasMany
    {
        return $this->hasMany(BackupJob::class, 'volume_name', 'name')
            ->where('source_type', BackupJob::SOURCE_TYPE_DOCKER_VOLUME);
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(Host::class);
    }
}
