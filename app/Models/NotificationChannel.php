<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class NotificationChannel extends Model
{
    use HasFactory;

    public const SERVICE_DISCORD = 'discord';

    public const SERVICE_TELEGRAM = 'telegram';

    public const SERVICE_NTFY = 'ntfy';

    public const SERVICE_GOTIFY = 'gotify';

    public const SERVICE_SMTP = 'smtp';

    public const SERVICE_ADVANCED = 'advanced';

    public const SERVICES = [
        self::SERVICE_DISCORD,
        self::SERVICE_TELEGRAM,
        self::SERVICE_NTFY,
        self::SERVICE_GOTIFY,
        self::SERVICE_SMTP,
        self::SERVICE_ADVANCED,
    ];

    public const LEVEL_ERROR = 'error';

    public const LEVEL_INFO = 'info';

    public const LEVELS = [self::LEVEL_ERROR, self::LEVEL_INFO];

    public const SCOPE_ALL = 'all';

    public const SCOPE_SPECIFIC = 'specific';

    public const SCOPES = [self::SCOPE_ALL, self::SCOPE_SPECIFIC];

    protected $fillable = [
        'name',
        'service',
        'url',
        'notification_level',
        'scope',
        'title_template',
        'body_template',
        'is_active',
        'last_tested_at',
        'last_test_status',
        'last_test_error',
    ];

    protected $hidden = ['url'];

    protected function casts(): array
    {
        return [
            'url' => 'encrypted',
            'is_active' => 'boolean',
            'last_tested_at' => 'datetime',
        ];
    }

    public function backupJobs(): BelongsToMany
    {
        return $this->belongsToMany(BackupJob::class)->withTimestamps();
    }

    public function safeForFrontend(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'service' => $this->service,
            'notification_level' => $this->notification_level,
            'scope' => $this->scope,
            'title_template' => $this->title_template,
            'body_template' => $this->body_template,
            'is_active' => $this->is_active,
            'last_tested_at' => $this->last_tested_at,
            'last_test_status' => $this->last_test_status,
            'last_test_error' => $this->last_test_error,
            'backup_job_ids' => $this->backupJobs->pluck('id')->values()->all(),
            'has_url' => filled($this->getRawOriginal('url')),
            'masked_url' => '********',
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
