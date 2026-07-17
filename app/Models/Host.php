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

    public const STATUSES = [self::STATUS_ONLINE, self::STATUS_OFFLINE, self::STATUS_ERROR];

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
        'enrollment_request_id',
        'enrollment_token_consumed_at',
        'agent_token_hash',
        'active_agent_command_id',
        'enrolled_at',
        'last_error',
    ];

    protected $hidden = [
        'enrollment_token_hash',
        'agent_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'active_agent_command_id' => 'integer',
            'last_seen_at' => 'datetime',
            'capabilities' => 'array',
            'metadata' => 'array',
            'enrollment_token_expires_at' => 'datetime',
            'enrollment_token_consumed_at' => 'datetime',
            'enrolled_at' => 'datetime',
        ];
    }

    public function scopeLocal(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_LOCAL);
    }

    public function scopeAgents(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_AGENT);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
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

    public function agentCommands(): HasMany
    {
        return $this->hasMany(AgentCommand::class);
    }

    /**
     * @return array{id: int, name: string, type: string, status: string, is_active: bool, last_seen_at: mixed, agent_version: string|null, docker_version: string|null, capabilities: array<mixed>, metadata: array<mixed>, enrolled_at: mixed, last_error: string|null, created_at: mixed, updated_at: mixed}
     */
    public function safeForFrontend(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'status' => $this->status,
            'is_active' => $this->is_active,
            'last_seen_at' => $this->last_seen_at,
            'agent_version' => $this->agent_version,
            'docker_version' => $this->docker_version,
            'capabilities' => $this->capabilities ?: [],
            'metadata' => $this->metadata ?: [],
            'enrolled_at' => $this->enrolled_at,
            'last_error' => $this->last_error,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
