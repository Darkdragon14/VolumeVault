<?php

namespace App\Models;

use App\Models\Relations\DockerVolumeBackupJobs;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DockerVolume extends Model
{
    use HasFactory;

    protected $fillable = [
        'docker_host_id',
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
            'docker_host_id' => 'integer',
            'labels' => 'array',
            'options' => 'array',
            'exists' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    protected $attributes = ['docker_host_id' => DockerHost::LOCAL_ID];

    public function dockerHost(): BelongsTo
    {
        return $this->belongsTo(DockerHost::class);
    }

    public function backupJobs(): HasMany
    {
        $related = $this->newRelatedInstance(BackupJob::class);

        return (new DockerVolumeBackupJobs($related->newQuery(), $this, $related->qualifyColumn('volume_name'), 'name'))
            ->where('source_type', BackupJob::SOURCE_TYPE_DOCKER_VOLUME);
    }

    public function isAvailable(): bool
    {
        return (bool) $this->getAttribute('exists');
    }
}
