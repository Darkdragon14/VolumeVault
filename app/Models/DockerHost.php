<?php

namespace App\Models;

use Database\Factories\DockerHostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class DockerHost extends Model
{
    /** @use HasFactory<DockerHostFactory> */
    use HasFactory;

    public const LOCAL_ID = 1;

    public const DRIVER_LOCAL = 'local';

    public const DRIVER_AGENT = 'agent';

    protected $fillable = ['name'];

    protected $attributes = ['driver' => self::DRIVER_AGENT];

    protected $hidden = [
        'maintenance_token', 'agent_maintenance_token',
        'agent_enrollment_hash', 'agent_token_hash', 'agent_instance_id',
        'agent_containers', 'agent_host_path_allowlist',
    ];

    protected function casts(): array
    {
        return [
            'docker_container_count' => 'integer',
            'maintenance_requested_at' => 'datetime',
            'agent_protocol_version' => 'integer',
            'agent_capabilities' => 'array',
            'agent_active_operations' => 'integer',
            'agent_enrollment_expires_at' => 'datetime',
            'agent_registered_at' => 'datetime',
            'agent_revoked_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_inventory_at' => 'datetime',
            'agent_inventory_sequence' => 'integer',
            'agent_containers' => 'array',
            'agent_host_path_allowlist' => 'array',
        ];
    }

    public function agentStatus(): string
    {
        if ($this->isLocal()) {
            return 'local';
        }

        if ($this->agent_revoked_at !== null) {
            return 'revoked';
        }

        if ($this->agent_registered_at === null) {
            return 'pending';
        }

        return $this->last_seen_at?->greaterThan(now()->subSeconds(90)) ? 'online' : 'offline';
    }

    protected static function booted(): void
    {
        static::creating(function (self $host): void {
            $host->uuid ??= (string) Str::uuid();
        });
    }

    public function isLocal(): bool
    {
        return $this->getKey() === self::LOCAL_ID && $this->driver === self::DRIVER_LOCAL;
    }

    public function volumes(): HasMany
    {
        return $this->hasMany(DockerVolume::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(BackupJob::class);
    }

    public function dockerLabelBackupSetting(): HasOne
    {
        return $this->hasOne(DockerLabelBackupSetting::class);
    }

    public function backupRuns(): HasMany
    {
        return $this->hasMany(BackupRun::class);
    }
}
