<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DockerLabelBackupSetting extends Model
{
    protected $fillable = [
        'enabled',
        'backup_destination_id',
        'defaults',
        'last_sync_error',
        'last_synced_at',
    ];

    protected $attributes = [
        'enabled' => false,
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'defaults' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(BackupDestination::class, 'backup_destination_id');
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate(['id' => 1], [
            'defaults' => self::defaultValues(),
        ]);
    }

    public static function defaultValues(): array
    {
        return [
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'timezone' => null,
            'retention_days' => null,
            'retention_count' => null,
            'backup_filter_mode' => BackupJob::FILTER_MODE_EXCLUDE,
            'backup_include_paths' => null,
            'backup_exclude_regexp' => null,
            'backup_filename_template' => null,
            'notifications_enabled' => true,
            'notification_channel_ids' => [],
            'alert_notifications_enabled' => true,
            'stop_containers_before_backup' => false,
        ];
    }

    public function resolvedDefaults(): array
    {
        return array_replace_recursive(self::defaultValues(), $this->defaults ?? []);
    }

    public static function usesEnabledDestination(BackupDestination $destination): bool
    {
        return self::query()
            ->where('enabled', true)
            ->where('backup_destination_id', $destination->id)
            ->exists();
    }
}
