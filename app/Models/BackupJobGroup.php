<?php

namespace App\Models;

use App\Services\Agents\AgentExecution;
use App\Support\DeploymentMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A backup group owns the schedule, notifications and failure policy for a set
 * of member backup jobs. Members remain ordinary {@see BackupJob} rows (one
 * source each): the group only orchestrates their runs and emits a single
 * aggregated start/success/fail notification for the whole set.
 */
class BackupJobGroup extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_ERROR = 'error';

    public const STATUS_RUNNING = 'running';

    public const SCHEDULE_HOURLY = 'hourly';

    public const SCHEDULE_DAILY = 'daily';

    public const SCHEDULE_WEEKLY = 'weekly';

    public const SCHEDULE_CRON = 'cron';

    public const FAILURE_POLICY_CONTINUE = 'continue';

    public const FAILURE_POLICY_STOP = 'stop';

    protected $fillable = [
        'name',
        'schedule_type',
        'schedule_config',
        'cron_expression',
        'timezone',
        'status',
        'pause_reason',
        'failure_policy',
        'notifications_enabled',
        'last_run_at',
        'next_run_at',
        'last_success_at',
        'last_error',
        'last_error_at',
    ];

    protected $attributes = [
        'notifications_enabled' => true,
        'failure_policy' => self::FAILURE_POLICY_CONTINUE,
    ];

    protected function casts(): array
    {
        return [
            'schedule_config' => 'array',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_error_at' => 'datetime',
            'notifications_enabled' => 'boolean',
        ];
    }

    public function members(): HasMany
    {
        return $this->hasMany(BackupJob::class, 'backup_job_group_id');
    }

    /**
     * Members a group run should back up: everything except paused ones. An
     * errored member is retried on the next run (the group owns scheduling, so a
     * transient failure must not drop a volume until it is manually resumed); a
     * successful retry clears its error. Pausing a member is the deliberate way
     * to skip its volume.
     */
    public function runnableMembers(): HasMany
    {
        return $this->members()->where('status', '!=', BackupJob::STATUS_PAUSED);
    }

    public function groupRuns(): HasMany
    {
        return $this->hasMany(BackupGroupRun::class)->latest();
    }

    /**
     * Advisory UI admission using eagerly loaded members; execution rechecks its guards.
     * Maintenance includes paused members, matching HostWorkAdmission::assertGroupAccepting.
     *
     * @return array{can_run: bool, can_run_reason: ?string}
     */
    public function runAvailability(): array
    {
        $members = $this->members;
        $runnable = $members->where('status', '!=', BackupJob::STATUS_PAUSED);
        $reason = match (true) {
            $this->status !== self::STATUS_ACTIVE, (bool) $this->has_pending_run, $runnable->isEmpty() => 'hostWorkflow.groupRunUnavailable',
            ! DeploymentMode::localExecutionEnabled() && $runnable->contains('docker_host_id', DockerHost::LOCAL_ID) => 'dockerHosts.localDisabled',
            $members->contains(fn (BackupJob $member): bool => $member->dockerHost === null
                || $member->dockerHost->maintenance_requested_at !== null) => 'hostWorkflow.unavailable',
            ! $runnable->every(fn (BackupJob $member): bool => app(AgentExecution::class)
                ->supportsHost($member->dockerHost, 'backup-v1')) => 'hostWorkflow.unavailable',
            default => null,
        };

        return ['can_run' => $reason === null, 'can_run_reason' => $reason];
    }

    public function notificationChannels(): BelongsToMany
    {
        return $this->belongsToMany(NotificationChannel::class)->withTimestamps();
    }

    public function hasOutstandingFinalizations(): bool
    {
        return $this->groupRuns()->withOutstandingFinalizations()->exists();
    }

    public function stopsOnFirstFailure(): bool
    {
        return $this->failure_policy === self::FAILURE_POLICY_STOP;
    }
}
