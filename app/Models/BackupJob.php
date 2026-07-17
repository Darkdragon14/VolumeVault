<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class BackupJob extends Model
{
    use HasFactory;

    public const SOURCE_TYPE_DOCKER_VOLUME = 'docker_volume';

    public const SOURCE_TYPE_HOST_PATH = 'host_path';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_ERROR = 'error';

    public const STATUS_RUNNING = 'running';

    public const SCHEDULE_HOURLY = 'hourly';

    public const SCHEDULE_DAILY = 'daily';

    public const SCHEDULE_WEEKLY = 'weekly';

    public const SCHEDULE_CRON = 'cron';

    public const FILTER_MODE_EXCLUDE = 'exclude';

    public const FILTER_MODE_INCLUDE = 'include';

    protected $fillable = [
        'host_id',
        'name',
        'backup_job_group_id',
        'source_type',
        'volume_name',
        'host_path',
        'backup_destination_id',
        'schedule_type',
        'schedule_config',
        'cron_expression',
        'timezone',
        'status',
        'notifications_enabled',
        'use_custom_alert_settings',
        'alert_notifications_enabled',
        'pause_reason',
        'last_run_at',
        'next_run_at',
        'last_success_at',
        'last_error',
        'last_error_at',
        'retention_days',
        'retention_count',
        'backup_exclude_regexp',
        'backup_filter_mode',
        'backup_include_paths',
        'backup_filename_template',
        'stop_containers_before_backup',
        'stop_container_names',
    ];

    protected $attributes = [
        'notifications_enabled' => true,
        'use_custom_alert_settings' => false,
        'alert_notifications_enabled' => true,
    ];

    protected $appends = [
        'source_label',
    ];

    protected function casts(): array
    {
        return [
            'schedule_config' => 'array',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_error_at' => 'datetime',
            'retention_days' => 'integer',
            'retention_count' => 'integer',
            'notifications_enabled' => 'boolean',
            'use_custom_alert_settings' => 'boolean',
            'alert_notifications_enabled' => 'boolean',
            'stop_containers_before_backup' => 'boolean',
            'stop_container_names' => 'array',
        ];
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(BackupDestination::class, 'backup_destination_id');
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(Host::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(BackupJobGroup::class, 'backup_job_group_id');
    }

    /**
     * A group member delegates its schedule and notifications to its group: it is
     * never dispatched on its own and its runs stay silent (the group notifies).
     */
    public function isGroupMember(): bool
    {
        return $this->backup_job_group_id !== null;
    }

    public function sourceType(): string
    {
        return $this->source_type ?: self::SOURCE_TYPE_DOCKER_VOLUME;
    }

    public function isDockerVolumeSource(): bool
    {
        return $this->sourceType() === self::SOURCE_TYPE_DOCKER_VOLUME;
    }

    public function isHostPathSource(): bool
    {
        return $this->sourceType() === self::SOURCE_TYPE_HOST_PATH;
    }

    public function sourceName(): string
    {
        return $this->isHostPathSource()
            ? (string) $this->host_path
            : (string) $this->volume_name;
    }

    public function getSourceLabelAttribute(): string
    {
        return $this->sourceName();
    }

    public function runs(): HasMany
    {
        return $this->hasMany(BackupRun::class)->latest();
    }

    /**
     * Whether a backup OR restore run of this job still matters for crash recovery:
     * queued/running, or terminal but still owning containers it stopped and has not
     * restarted yet (a worker can mark a run SUCCESS then crash in its finally). Used
     * to refuse changing the source or deleting the job: RunBackup reloads the job
     * before mounting the source (a mid-run change would back up the wrong volume),
     * and deleting cascade-drops the run row ReconcileStaleRuns needs to restart the
     * stopped containers.
     */
    public function hasRunInProgress(): bool
    {
        return $this->runs()->activeOrHoldingContainers()->exists()
            || $this->restoreRuns()->activeOrHoldingContainers()->exists();
    }

    public function restoreRuns(): HasMany
    {
        return $this->hasMany(RestoreRun::class)->latest();
    }

    public function notificationChannels(): BelongsToMany
    {
        return $this->belongsToMany(NotificationChannel::class)->withTimestamps();
    }

    public function alertConfigs(): HasMany
    {
        return $this->hasMany(JobAlertConfig::class);
    }

    public function alerts(): MorphMany
    {
        return $this->morphMany(Alert::class, 'subject');
    }
}
