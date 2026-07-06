<?php

namespace Tests\Feature;

use App\Actions\Alerts\EnsureAlertRules;
use App\Actions\Alerts\RunAllAlertChecks;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\NotificationChannel;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proactive alerts must keep working on a job that belongs to a group: group
 * members are ordinary backup jobs, so the per-job alert checks evaluate them
 * exactly like standalone jobs (group-level alert *notifications* are a separate,
 * later concern).
 */
class GroupedBackupAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_backup_too_old_alert_fires_for_a_group_member_job(): void
    {
        $member = $this->groupMember(['last_success_at' => now()->subDays(8)]);
        $rule = $this->enabledRule(AlertType::BackupTooOld, ['backup_too_old_days' => 7]);

        app(RunAllAlertChecks::class)->handle($rule);

        $alert = Alert::firstOrFail();
        $this->assertSame(AlertStatus::Active, $alert->status);
        $this->assertSame(BackupJob::class, $alert->subject_type);
        $this->assertSame($member->id, $alert->subject_id);
    }

    public function test_job_in_error_too_long_alert_fires_for_a_group_member_job(): void
    {
        $member = $this->groupMember([
            'status' => BackupJob::STATUS_ERROR,
            'last_error' => 'volume unreadable',
            'last_error_at' => now()->subDays(5),
        ]);
        $rule = $this->enabledRule(AlertType::JobInErrorTooLong, ['job_in_error_days' => 3]);

        app(RunAllAlertChecks::class)->handle($rule);

        $this->assertDatabaseHas('alerts', [
            'alert_rule_id' => $rule->id,
            'subject_type' => BackupJob::class,
            'subject_id' => $member->id,
            'status' => AlertStatus::Active->value,
        ]);
    }

    public function test_a_member_alert_is_delivered_through_the_group_channels(): void
    {
        $docker = new class extends DockerProcess
        {
            /** @var array<int, string> */
            public array $shoutrrrUrls = [];

            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                if (array_key_exists('SHOUTRRR_URL', $environment)) {
                    $this->shoutrrrUrls[] = $environment['SHOUTRRR_URL'];
                }

                return new DockerProcessResult($command, 0, '', '');
            }
        };
        $this->app->instance(DockerProcess::class, $docker);

        $member = $this->groupMember(['last_success_at' => now()->subDays(8)]);
        $channel = NotificationChannel::create([
            'name' => 'Group alerts',
            'service' => NotificationChannel::SERVICE_NTFY,
            'url' => 'GROUP_ALERT_URL',
            'notification_level' => NotificationChannel::LEVEL_ERROR,
            'is_active' => true,
        ]);
        $member->group->notificationChannels()->attach($channel->id);

        $rule = $this->enabledRule(AlertType::BackupTooOld, ['backup_too_old_days' => 7]);
        app(RunAllAlertChecks::class)->handle($rule);

        $this->assertContains('GROUP_ALERT_URL', $docker->shoutrrrUrls, 'the member alert should ping the group channel');
    }

    private function groupMember(array $attributes): BackupJob
    {
        $destination = BackupDestination::create([
            'name' => 'S3',
            'provider' => BackupDestination::PROVIDER_AWS_S3,
            'bucket' => 'backups',
            'access_key_id' => 'access',
            'secret_access_key' => 'secret',
        ]);

        $group = BackupJobGroup::create([
            'name' => 'Group',
            'schedule_type' => BackupJobGroup::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJobGroup::STATUS_ACTIVE,
            'failure_policy' => BackupJobGroup::FAILURE_POLICY_CONTINUE,
            'next_run_at' => now()->addDay(),
        ]);

        return BackupJob::create([
            'name' => 'Member',
            'backup_job_group_id' => $group->id,
            'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
            'volume_name' => 'member_vol',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => $attributes['status'] ?? BackupJob::STATUS_ACTIVE,
            'last_success_at' => $attributes['last_success_at'] ?? null,
            'last_error' => $attributes['last_error'] ?? null,
            'last_error_at' => $attributes['last_error_at'] ?? null,
            'next_run_at' => null,
        ]);
    }

    private function enabledRule(AlertType $type, array $config = []): AlertRule
    {
        app(EnsureAlertRules::class)->handle();

        $rule = AlertRule::where('type', $type->value)->firstOrFail();
        $rule->forceFill([
            'enabled' => true,
            'config' => array_replace($rule->config ?? [], $config),
        ])->save();

        return $rule;
    }
}
