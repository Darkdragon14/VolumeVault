<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Host extends Model
{
    use HasFactory;

    public const TYPE_LOCAL = 'local';

    public const TYPE_AGENT = 'agent';

    public const STATUS_ONLINE = 'online';

    public const STATUS_OFFLINE = 'offline';

    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'name',
        'type',
        'status',
        'is_active',
        'last_seen_at',
        'agent_version',
        'docker_version',
        'capabilities',
        'metadata',
        'enrollment_token_hash',
        'enrollment_token_expires_at',
        'enrolled_at',
        'last_error',
    ];

    protected $hidden = [
        'enrollment_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
            'capabilities' => 'array',
            'metadata' => 'array',
            'enrollment_token_expires_at' => 'datetime',
            'enrolled_at' => 'datetime',
        ];
    }

    public function scopeLocal(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_LOCAL);
    }

    public static function localHost(): self
    {
        return self::query()->local()->sole();
    }

    public function dockerVolumes(): HasMany
    {
        return $this->hasMany(DockerVolume::class);
    }

    public function backupJobs(): HasMany
    {
        return $this->hasMany(BackupJob::class);
    }

    public function backupRuns(): HasMany
    {
        return $this->hasMany(BackupRun::class);
    }

    public function restoreRuns(): HasMany
    {
        return $this->hasMany(RestoreRun::class);
    }
}
