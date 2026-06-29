<?php

namespace Tests\Feature;

use App\Actions\Alerts\EnsureAlertRules;
use App\Actions\Alerts\RunAllAlertChecks;
use App\Enums\AlertEventType;
use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Jobs\RunAlertChecksJob;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\JobAlertConfig;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class AlertSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_alert_rules_are_initialized_disabled_by_default(): void
    {
        app(EnsureAlertRules::class)->handle();

        $this->assertSame(5, AlertRule::count());
        $this->assertTrue(AlertRule::where('enabled', false)->count() === 5);
    }

    public function test_backup_too_old_alert_triggers_and_resolves(): void
    {
        $job = $this->backupJob([
            'last_success_at' => now()->subDays(8),
        ]);
        $rule = $this->enabledRule(AlertType::BackupTooOld, ['backup_too_old_days' => 7]);

        app(RunAllAlertChecks::class)->handle($rule);

        $alert = Alert::firstOrFail();
        $this->assertSame(AlertStatus::Active, $alert->status);
        $this->assertSame(AlertSeverity::Warning, $alert->severity);
        $this->assertSame(1, $alert->trigger_count);

        $job->forceFill(['last_success_at' => now()])->save();

        app(RunAllAlertChecks::class)->handle($rule);

        $this->assertSame(AlertStatus::Resolved, $alert->fresh()->status);
        $this->assertDatabaseHas('alert_events', [
            'alert_id' => $alert->id,
            'event_type' => AlertEventType::Resolved->value,
        ]);
    }

    public function test_backup_too_old_alert_resolves_when_job_is_paused(): void
    {
        $job = $this->backupJob([
            'last_success_at' => now()->subDays(8),
        ]);
        $rule = $this->enabledRule(AlertType::BackupTooOld, ['backup_too_old_days' => 7]);

        app(RunAllAlertChecks::class)->handle($rule);
        $alert = Alert::firstOrFail();

        $job->forceFill(['status' => BackupJob::STATUS_PAUSED])->save();

        app(RunAllAlertChecks::class)->handle($rule);

        $this->assertSame(AlertStatus::Resolved, $alert->fresh()->status);
    }

    public function test_job_never_succeeded_alert_uses_finished_run_threshold(): void
    {
        $job = $this->backupJob(['last_success_at' => null]);
        $rule = $this->enabledRule(AlertType::JobNeverSucceeded, ['job_never_succeeded_min_runs' => 3]);

        BackupRun::create(['backup_job_id' => $job->id, 'status' => BackupRun::STATUS_FAILED, 'trigger' => BackupRun::TRIGGER_SCHEDULED]);
        BackupRun::create(['backup_job_id' => $job->id, 'status' => BackupRun::STATUS_FAILED, 'trigger' => BackupRun::TRIGGER_SCHEDULED]);

        app(RunAllAlertChecks::class)->handle($rule);
        $this->assertSame(0, Alert::count());

        BackupRun::create(['backup_job_id' => $job->id, 'status' => BackupRun::STATUS_FAILED, 'trigger' => BackupRun::TRIGGER_SCHEDULED]);

        app(RunAllAlertChecks::class)->handle($rule);
        $this->assertSame(AlertStatus::Active, Alert::firstOrFail()->status);
    }

    public function test_job_never_succeeded_alert_resolves_when_job_is_paused(): void
    {
        $job = $this->backupJob(['last_success_at' => null]);
        $rule = $this->enabledRule(AlertType::JobNeverSucceeded, ['job_never_succeeded_min_runs' => 3]);

        BackupRun::create(['backup_job_id' => $job->id, 'status' => BackupRun::STATUS_FAILED, 'trigger' => BackupRun::TRIGGER_SCHEDULED]);
        BackupRun::create(['backup_job_id' => $job->id, 'status' => BackupRun::STATUS_FAILED, 'trigger' => BackupRun::TRIGGER_SCHEDULED]);
        BackupRun::create(['backup_job_id' => $job->id, 'status' => BackupRun::STATUS_FAILED, 'trigger' => BackupRun::TRIGGER_SCHEDULED]);

        app(RunAllAlertChecks::class)->handle($rule);
        $alert = Alert::firstOrFail();

        $job->forceFill(['status' => BackupJob::STATUS_PAUSED])->save();

        app(RunAllAlertChecks::class)->handle($rule);

        $this->assertSame(AlertStatus::Resolved, $alert->fresh()->status);
    }

    public function test_job_in_error_too_long_uses_last_error_at(): void
    {
        $job = $this->backupJob([
            'status' => BackupJob::STATUS_ERROR,
            'last_error' => 'Boom',
            'last_error_at' => now(),
            'updated_at' => now()->subDays(10),
        ]);
        $rule = $this->enabledRule(AlertType::JobInErrorTooLong, ['job_in_error_days' => 3]);

        app(RunAllAlertChecks::class)->handle($rule);
        $this->assertSame(0, Alert::count());

        $job->forceFill(['last_error_at' => now()->subDays(4)])->save();

        app(RunAllAlertChecks::class)->handle($rule);
        $this->assertSame(AlertStatus::Active, Alert::firstOrFail()->status);
    }

    public function test_backup_size_alert_skips_successful_runs_without_size(): void
    {
        $job = $this->backupJob();
        $rule = $this->enabledRule(AlertType::BackupSizeOutOfRange);

        BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_SUCCESS,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'backup_size_bytes' => null,
        ]);

        app(RunAllAlertChecks::class)->handle($rule);

        $this->assertSame(0, Alert::count());
    }

    public function test_backup_size_alert_uses_latest_successful_known_size(): void
    {
        $job = $this->backupJob();
        $rule = $this->enabledRule(AlertType::BackupSizeOutOfRange, [
            'backup_size_out_of_range_min_bytes' => 1024,
            'backup_size_out_of_range_max_bytes' => 4096,
        ]);
        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_SUCCESS,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'backup_size_bytes' => 128,
            'finished_at' => now(),
        ]);

        app(RunAllAlertChecks::class)->handle($rule);

        $alert = Alert::firstOrFail();
        $this->assertSame(AlertSeverity::Critical, $alert->severity);
        $this->assertSame($run->id, $alert->context['backup_run_id']);
    }

    public function test_backup_size_alert_resolves_when_job_is_paused(): void
    {
        $job = $this->backupJob();
        $rule = $this->enabledRule(AlertType::BackupSizeOutOfRange, [
            'backup_size_out_of_range_min_bytes' => 1024,
            'backup_size_out_of_range_max_bytes' => 4096,
        ]);

        BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_SUCCESS,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'backup_size_bytes' => 128,
            'finished_at' => now(),
        ]);

        app(RunAllAlertChecks::class)->handle($rule);
        $alert = Alert::firstOrFail();

        $job->forceFill(['status' => BackupJob::STATUS_PAUSED])->save();

        app(RunAllAlertChecks::class)->handle($rule);

        $this->assertSame(AlertStatus::Resolved, $alert->fresh()->status);
    }

    public function test_backup_size_alert_allows_unbounded_maximum(): void
    {
        $job = $this->backupJob();
        $rule = $this->enabledRule(AlertType::BackupSizeOutOfRange, [
            'backup_size_out_of_range_min_bytes' => 1024,
            'backup_size_out_of_range_max_bytes' => null,
        ]);

        BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_SUCCESS,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'backup_size_bytes' => 20 * 1024 * 1024 * 1024,
            'finished_at' => now(),
        ]);

        app(RunAllAlertChecks::class)->handle($rule);

        $this->assertSame(0, Alert::count());
    }

    public function test_destination_storage_limit_alert_uses_destination_thresholds_and_delta(): void
    {
        $directory = $this->storageLimitDirectory('delta');
        File::put($directory.'/first.tar.gz', str_repeat('x', 2048));
        $destination = $this->localDestination($directory, [
            'storage_limit_warning_bytes' => 1024,
            'storage_limit_critical_bytes' => 4096,
        ]);
        $rule = $this->enabledRule(AlertType::DestinationStorageLimit);

        app(RunAllAlertChecks::class)->handle($rule);

        $alert = Alert::firstOrFail();
        $this->assertSame(AlertSeverity::Warning, $alert->severity);
        $this->assertSame(2048, $alert->context['used_bytes']);
        $this->assertNull($alert->context['delta_bytes']);

        File::put($directory.'/second.tar.gz', str_repeat('x', 3072));

        Cache::forget('destination_storage_usage_bytes_'.$destination->id);
        app(RunAllAlertChecks::class)->handle($rule);

        $alert->refresh();
        $this->assertSame(AlertSeverity::Critical, $alert->severity);
        $this->assertSame(5120, $alert->context['used_bytes']);
        $this->assertSame(2048, $alert->context['previous_used_bytes']);
        $this->assertSame(3072, $alert->context['delta_bytes']);
    }

    public function test_alert_escalation_sends_notification_without_reminders(): void
    {
        $directory = $this->storageLimitDirectory('escalation');
        File::put($directory.'/archive.tar.gz', str_repeat('x', 2048));
        $destination = $this->localDestination($directory, [
            'storage_limit_warning_bytes' => 1024,
            'storage_limit_critical_bytes' => 4096,
        ]);
        $rule = $this->enabledRule(AlertType::DestinationStorageLimit, [
            'reminder_enabled' => false,
        ]);
        $channel = NotificationChannel::create([
            'name' => 'Storage alerts',
            'service' => NotificationChannel::SERVICE_ADVANCED,
            'url' => 'ntfy://ntfy.sh/storage',
            'notification_level' => NotificationChannel::LEVEL_ERROR,
        ]);
        $rule->notificationChannels()->attach($channel);

        $dockerProcess = Mockery::mock(DockerProcess::class);
        $dockerProcess->shouldReceive('run')->twice()->andReturn(new DockerProcessResult([], 0, 'ok', ''));
        $this->app->instance(DockerProcess::class, $dockerProcess);

        app(RunAllAlertChecks::class)->handle($rule);
        File::put($directory.'/larger.tar.gz', str_repeat('x', 3072));
        Cache::forget('destination_storage_usage_bytes_'.$destination->id);
        app(RunAllAlertChecks::class)->handle($rule);

        $this->assertSame(AlertSeverity::Critical, Alert::firstOrFail()->severity);
        $this->assertSame(2, Alert::firstOrFail()->events()->where('event_type', AlertEventType::Notified->value)->count());
    }

    public function test_disabled_destination_storage_limit_alert_does_not_trigger(): void
    {
        $directory = $this->storageLimitDirectory('disabled');
        File::put($directory.'/archive.tar.gz', str_repeat('x', 2048));
        $this->localDestination($directory, ['storage_limit_warning_bytes' => 1024]);
        $rule = $this->disabledRule(AlertType::DestinationStorageLimit);

        app(RunAllAlertChecks::class)->handle($rule);

        $this->assertSame(0, Alert::count());
    }

    public function test_destination_storage_limit_alert_resolves_when_usage_returns_below_threshold(): void
    {
        $directory = $this->storageLimitDirectory('resolve');
        File::put($directory.'/archive.tar.gz', str_repeat('x', 2048));
        $destination = $this->localDestination($directory, [
            'storage_limit_warning_bytes' => 1024,
            'storage_limit_critical_bytes' => 4096,
        ]);
        $rule = $this->enabledRule(AlertType::DestinationStorageLimit);

        app(RunAllAlertChecks::class)->handle($rule);
        $alert = Alert::firstOrFail();

        File::cleanDirectory($directory);

        Cache::forget('destination_storage_usage_bytes_'.$destination->id);
        app(RunAllAlertChecks::class)->handle($rule);

        $this->assertSame(AlertStatus::Resolved, $alert->fresh()->status);
    }

    public function test_resolved_alert_notifications_use_neutral_message_without_stale_context(): void
    {
        $directory = $this->storageLimitDirectory('resolved-message');
        File::put($directory.'/archive.tar.gz', str_repeat('x', 2048));
        $destination = $this->localDestination($directory, ['storage_limit_warning_bytes' => 1024]);
        $rule = $this->enabledRule(AlertType::DestinationStorageLimit);
        $channel = NotificationChannel::create([
            'name' => 'Storage alerts',
            'service' => NotificationChannel::SERVICE_ADVANCED,
            'url' => 'ntfy://ntfy.sh/storage',
            'notification_level' => NotificationChannel::LEVEL_ERROR,
        ]);
        $rule->notificationChannels()->attach($channel);
        $dockerProcess = new class extends DockerProcess
        {
            public array $messages = [];

            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                $messageIndex = array_search('--message', $command, true);

                if (is_int($messageIndex)) {
                    $this->messages[] = (string) ($command[$messageIndex + 1] ?? '');
                }

                return new DockerProcessResult($command, 0, 'ok', '');
            }
        };
        $this->app->instance(DockerProcess::class, $dockerProcess);

        app(RunAllAlertChecks::class)->handle($rule);
        File::cleanDirectory($directory);
        Cache::forget('destination_storage_usage_bytes_'.$destination->id);
        app(RunAllAlertChecks::class)->handle($rule);

        $resolvedMessage = collect($dockerProcess->messages)->first(
            fn (string $msg) => str_contains($msg, 'Alert condition is resolved')
        );

        $this->assertNotNull($resolvedMessage, 'Expected a resolved notification to be sent.');
        $this->assertStringContainsString('Message: Alert condition is resolved.', $resolvedMessage);
        $this->assertStringNotContainsString('Context:', $resolvedMessage);
        $this->assertStringNotContainsString('is using 2 KB of backup storage', $resolvedMessage);
    }

    public function test_destination_storage_limit_alert_uses_rule_notification_channels(): void
    {
        $directory = $this->storageLimitDirectory('notify');
        File::put($directory.'/archive.tar.gz', str_repeat('x', 2048));
        $this->localDestination($directory, ['storage_limit_warning_bytes' => 1024]);
        $rule = $this->enabledRule(AlertType::DestinationStorageLimit);
        $channel = NotificationChannel::create([
            'name' => 'Storage alerts',
            'service' => NotificationChannel::SERVICE_ADVANCED,
            'url' => 'ntfy://ntfy.sh/storage',
            'notification_level' => NotificationChannel::LEVEL_ERROR,
        ]);
        $rule->notificationChannels()->attach($channel);

        $dockerProcess = Mockery::mock(DockerProcess::class);
        $dockerProcess->shouldReceive('run')->once()->andReturn(new DockerProcessResult([], 0, 'ok', ''));
        $this->app->instance(DockerProcess::class, $dockerProcess);

        app(RunAllAlertChecks::class)->handle($rule);

        $this->assertDatabaseHas('alert_events', [
            'event_type' => AlertEventType::Notified->value,
        ]);
    }

    public function test_webhook_alert_channel_without_the_event_url_is_not_recorded_as_notified(): void
    {
        $directory = $this->storageLimitDirectory('webhook-skip');
        File::put($directory.'/archive.tar.gz', str_repeat('x', 2048));
        $this->localDestination($directory, ['storage_limit_warning_bytes' => 1024]);
        $rule = $this->enabledRule(AlertType::DestinationStorageLimit);

        // The webhook channel only has a success URL. An alert maps to the fail event,
        // which has no URL, so nothing is pinged — and nothing must be recorded as a
        // notified send (the no-op skip used to be counted as a successful delivery).
        $channel = NotificationChannel::create([
            'name' => 'Webhook alerts',
            'service' => NotificationChannel::SERVICE_WEBHOOK,
            'url' => json_encode(['success' => 'generic+https://example.com/ok']),
            'notification_level' => NotificationChannel::LEVEL_ERROR,
        ]);
        $rule->notificationChannels()->attach($channel);

        $dockerProcess = Mockery::mock(DockerProcess::class);
        $dockerProcess->shouldReceive('run')->never();
        $this->app->instance(DockerProcess::class, $dockerProcess);

        app(RunAllAlertChecks::class)->handle($rule);

        $this->assertSame(AlertStatus::Active, Alert::firstOrFail()->status);
        $this->assertDatabaseMissing('alert_events', [
            'event_type' => AlertEventType::Notified->value,
        ]);
    }

    public function test_destination_storage_limit_alert_without_channels_stays_in_app_only(): void
    {
        $directory = $this->storageLimitDirectory('silent');
        File::put($directory.'/archive.tar.gz', str_repeat('x', 2048));
        $this->localDestination($directory, ['storage_limit_warning_bytes' => 1024]);
        $rule = $this->enabledRule(AlertType::DestinationStorageLimit);

        $dockerProcess = Mockery::mock(DockerProcess::class);
        $dockerProcess->shouldReceive('run')->never();
        $this->app->instance(DockerProcess::class, $dockerProcess);

        app(RunAllAlertChecks::class)->handle($rule);

        $this->assertSame(AlertStatus::Active, Alert::firstOrFail()->status);
        $this->assertDatabaseMissing('alert_events', [
            'event_type' => AlertEventType::Notified->value,
        ]);
    }

    public function test_job_custom_alert_settings_can_enable_a_globally_disabled_rule(): void
    {
        $job = $this->backupJob([
            'last_success_at' => now()->subDays(8),
            'use_custom_alert_settings' => true,
        ]);
        $rule = $this->disabledRule(AlertType::BackupTooOld);
        JobAlertConfig::create([
            'backup_job_id' => $job->id,
            'alert_rule_id' => $rule->id,
            'enabled' => true,
            'config' => ['backup_too_old_days' => 7],
        ]);

        app(RunAllAlertChecks::class)->handle($rule);

        $this->assertSame(AlertStatus::Active, Alert::firstOrFail()->status);
    }

    public function test_scheduled_alert_checks_include_enabled_job_overrides_for_disabled_global_rules(): void
    {
        Cache::flush();
        $job = $this->backupJob([
            'last_success_at' => now()->subDays(8),
            'use_custom_alert_settings' => true,
        ]);
        $rule = $this->disabledRule(AlertType::BackupTooOld);
        JobAlertConfig::create([
            'backup_job_id' => $job->id,
            'alert_rule_id' => $rule->id,
            'enabled' => true,
            'config' => ['backup_too_old_days' => 7],
        ]);

        app()->call([new RunAlertChecksJob, 'handle']);

        $this->assertSame(AlertStatus::Active, Alert::firstOrFail()->status);
    }

    public function test_alert_check_failure_does_not_block_remaining_rules(): void
    {
        Cache::flush();
        app(EnsureAlertRules::class)->handle();
        $this->assertSame(5, AlertRule::count());

        $handled = [];
        $mock = Mockery::mock(RunAllAlertChecks::class);
        $mock->shouldReceive('handle')
            ->times(5)
            ->andReturnUsing(function (AlertRule $rule) use (&$handled): void {
                $handled[] = $rule->id;

                if (count($handled) === 1) {
                    throw new \RuntimeException('boom');
                }
            });
        $this->app->instance(RunAllAlertChecks::class, $mock);

        app()->call([new RunAlertChecksJob, 'handle']);

        $this->assertCount(5, $handled, 'Every rule should be evaluated even when one throws.');
        $this->assertDatabaseHas('activity_logs', [
            'event_type' => 'alert_check_failed',
        ]);
    }

    public function test_alert_index_filters_by_type(): void
    {
        $job = $this->backupJob(['last_success_at' => now()->subDays(8)]);
        $oldBackupRule = $this->disabledRule(AlertType::BackupTooOld);
        $neverSucceededRule = $this->disabledRule(AlertType::JobNeverSucceeded);
        $otherJob = $this->backupJob(['name' => 'Other job']);

        Alert::create([
            'alert_rule_id' => $oldBackupRule->id,
            'subject_type' => $job->getMorphClass(),
            'subject_id' => $job->id,
            'status' => AlertStatus::Active,
            'severity' => AlertSeverity::Warning,
            'message' => 'Backup too old.',
            'trigger_count' => 1,
            'first_triggered_at' => now(),
            'last_triggered_at' => now(),
        ]);
        Alert::create([
            'alert_rule_id' => $neverSucceededRule->id,
            'subject_type' => $otherJob->getMorphClass(),
            'subject_id' => $otherJob->id,
            'status' => AlertStatus::Active,
            'severity' => AlertSeverity::Critical,
            'message' => 'Never succeeded.',
            'trigger_count' => 1,
            'first_triggered_at' => now(),
            'last_triggered_at' => now(),
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('alerts.index', ['type' => AlertType::BackupTooOld->value]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Alerts/Index')
                ->has('alerts.data', 1)
                ->where('alerts.data.0.type', AlertType::BackupTooOld->value));
    }

    public function test_alert_notifications_ignore_backup_notification_level(): void
    {
        $job = $this->backupJob(['last_success_at' => now()->subDays(8)]);
        $rule = $this->enabledRule(AlertType::BackupTooOld, ['backup_too_old_days' => 7]);
        $channel = NotificationChannel::create([
            'name' => 'Errors only',
            'service' => NotificationChannel::SERVICE_ADVANCED,
            'url' => 'ntfy://ntfy.sh/errors',
            'notification_level' => NotificationChannel::LEVEL_ERROR,
        ]);
        $job->notificationChannels()->attach($channel);

        $dockerProcess = Mockery::mock(DockerProcess::class);
        $dockerProcess->shouldReceive('run')->once()->andReturn(new DockerProcessResult([], 0, 'ok', ''));
        $this->app->instance(DockerProcess::class, $dockerProcess);

        app(RunAllAlertChecks::class)->handle($rule);

        $this->assertDatabaseHas('alert_events', [
            'event_type' => AlertEventType::Notified->value,
        ]);
    }

    public function test_alert_notification_toggle_does_not_block_alert_creation(): void
    {
        $job = $this->backupJob([
            'last_success_at' => now()->subDays(8),
            'alert_notifications_enabled' => false,
        ]);
        $rule = $this->enabledRule(AlertType::BackupTooOld, ['backup_too_old_days' => 7]);
        $channel = NotificationChannel::create([
            'name' => 'Errors only',
            'service' => NotificationChannel::SERVICE_ADVANCED,
            'url' => 'ntfy://ntfy.sh/errors',
            'notification_level' => NotificationChannel::LEVEL_ERROR,
        ]);
        $job->notificationChannels()->attach($channel);

        $dockerProcess = Mockery::mock(DockerProcess::class);
        $dockerProcess->shouldReceive('run')->never();
        $this->app->instance(DockerProcess::class, $dockerProcess);

        app(RunAllAlertChecks::class)->handle($rule);

        $this->assertSame(AlertStatus::Active, Alert::firstOrFail()->status);
    }

    public function test_alert_settings_update_allows_cleared_max_backup_size(): void
    {
        app(EnsureAlertRules::class)->handle();
        $rule = AlertRule::where('type', AlertType::BackupSizeOutOfRange->value)->firstOrFail();

        $this->actingAs(User::factory()->admin()->create())
            ->put('/alerts/settings', [
                'rules' => [[
                    'id' => $rule->id,
                    'enabled' => true,
                    'config' => [
                        'check_interval_minutes' => 60,
                        'cooldown_minutes' => 1440,
                        'reminder_enabled' => false,
                        'backup_size_out_of_range_min_bytes' => 1024,
                        'backup_size_out_of_range_max_bytes' => null,
                    ],
                ]],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('alerts.settings.edit'));

        $config = $rule->fresh()->config;

        $this->assertSame(1024, $config['backup_size_out_of_range_min_bytes']);
        $this->assertNull($config['backup_size_out_of_range_max_bytes']);

        app(EnsureAlertRules::class)->handle();

        $this->assertNull($rule->fresh()->config['backup_size_out_of_range_max_bytes']);
    }

    public function test_alert_settings_update_syncs_destination_alert_notification_channels(): void
    {
        app(EnsureAlertRules::class)->handle();
        $rule = AlertRule::where('type', AlertType::DestinationStorageLimit->value)->firstOrFail();
        $channel = NotificationChannel::create([
            'name' => 'Storage alerts',
            'service' => NotificationChannel::SERVICE_ADVANCED,
            'url' => 'ntfy://ntfy.sh/storage',
            'notification_level' => NotificationChannel::LEVEL_ERROR,
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->put('/alerts/settings', [
                'rules' => [[
                    'id' => $rule->id,
                    'enabled' => true,
                    'notification_channel_ids' => [$channel->id],
                    'config' => [
                        'check_interval_minutes' => 60,
                        'cooldown_minutes' => 1440,
                        'reminder_enabled' => false,
                    ],
                ]],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('alerts.settings.edit'));

        $this->assertTrue($rule->fresh()->notificationChannels()->whereKey($channel->id)->exists());
    }

    public function test_destination_storage_limits_require_critical_threshold_after_warning_threshold(): void
    {
        $directory = $this->storageLimitDirectory('validation');
        $destination = $this->localDestination($directory);

        $this->actingAs(User::factory()->admin()->create())
            ->put('/destinations/'.$destination->id, [
                'name' => $destination->name,
                'provider' => BackupDestination::PROVIDER_LOCAL,
                'is_active' => true,
                'settings' => [
                    'archive_path' => $directory,
                    'archive_mount_source' => $directory,
                    'storage_limit_warning_bytes' => 4096,
                    'storage_limit_critical_bytes' => 1024,
                ],
            ])
            ->assertSessionHasErrors('settings.storage_limit_critical_bytes');
    }

    public function test_job_alert_config_update_allows_cleared_max_backup_size_override(): void
    {
        app(EnsureAlertRules::class)->handle();
        $job = $this->backupJob();
        $rule = AlertRule::where('type', AlertType::BackupSizeOutOfRange->value)->firstOrFail();

        $this->actingAs(User::factory()->admin()->create())
            ->put('/backup-jobs/'.$job->id, [
                'name' => $job->name,
                'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
                'volume_name' => $job->volume_name,
                'backup_destination_id' => $job->backup_destination_id,
                'schedule_type' => $job->schedule_type,
                'schedule_config' => $job->schedule_config,
                'notifications_enabled' => true,
                'notification_channel_ids' => [],
                'use_custom_alert_settings' => true,
                'alert_notifications_enabled' => true,
                'alert_configs' => [[
                    'alert_rule_id' => $rule->id,
                    'enabled' => true,
                    'config' => [
                        'check_interval_minutes' => 5,
                        'cooldown_minutes' => 60,
                        'backup_size_out_of_range_min_bytes' => 1024,
                        'backup_size_out_of_range_max_bytes' => null,
                    ],
                ]],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('backup-jobs.index'));

        $config = JobAlertConfig::where('backup_job_id', $job->id)->where('alert_rule_id', $rule->id)->firstOrFail()->config;

        $this->assertArrayNotHasKey('check_interval_minutes', $config);
        $this->assertSame(60, $config['cooldown_minutes']);
        $this->assertSame(1024, $config['backup_size_out_of_range_min_bytes']);
        $this->assertNull($config['backup_size_out_of_range_max_bytes']);
    }

    public function test_job_alert_configs_are_cleared_when_custom_alert_settings_are_disabled(): void
    {
        app(EnsureAlertRules::class)->handle();
        $job = $this->backupJob(['use_custom_alert_settings' => true]);
        $rule = AlertRule::where('type', AlertType::BackupTooOld->value)->firstOrFail();
        JobAlertConfig::create([
            'backup_job_id' => $job->id,
            'alert_rule_id' => $rule->id,
            'enabled' => true,
            'config' => ['backup_too_old_days' => 14],
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->put('/backup-jobs/'.$job->id, [
                'name' => $job->name,
                'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
                'volume_name' => $job->volume_name,
                'backup_destination_id' => $job->backup_destination_id,
                'schedule_type' => $job->schedule_type,
                'schedule_config' => $job->schedule_config,
                'notifications_enabled' => true,
                'notification_channel_ids' => [],
                'use_custom_alert_settings' => false,
                'alert_notifications_enabled' => true,
                'alert_configs' => [[
                    'alert_rule_id' => $rule->id,
                    'enabled' => true,
                    'config' => ['backup_too_old_days' => 30],
                ]],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('backup-jobs.index'));

        $this->assertFalse($job->fresh()->use_custom_alert_settings);
        $this->assertSame(0, JobAlertConfig::where('backup_job_id', $job->id)->count());
    }

    public function test_job_update_without_custom_alert_toggle_preserves_existing_alert_overrides(): void
    {
        app(EnsureAlertRules::class)->handle();
        $job = $this->backupJob(['use_custom_alert_settings' => true]);
        $rule = AlertRule::where('type', AlertType::BackupTooOld->value)->firstOrFail();
        JobAlertConfig::create([
            'backup_job_id' => $job->id,
            'alert_rule_id' => $rule->id,
            'enabled' => true,
            'config' => ['backup_too_old_days' => 14],
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->put('/backup-jobs/'.$job->id, [
                'name' => $job->name,
                'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
                'volume_name' => $job->volume_name,
                'backup_destination_id' => $job->backup_destination_id,
                'schedule_type' => $job->schedule_type,
                'schedule_config' => $job->schedule_config,
                'notifications_enabled' => true,
                'notification_channel_ids' => [],
                'alert_notifications_enabled' => true,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('backup-jobs.index'));

        $this->assertTrue($job->fresh()->use_custom_alert_settings);
        $this->assertSame(14, JobAlertConfig::where('backup_job_id', $job->id)->where('alert_rule_id', $rule->id)->firstOrFail()->config['backup_too_old_days']);
    }

    public function test_disabled_custom_alert_settings_ignore_invalid_alert_configs(): void
    {
        app(EnsureAlertRules::class)->handle();
        $job = $this->backupJob(['use_custom_alert_settings' => true]);
        $rule = AlertRule::where('type', AlertType::BackupSizeOutOfRange->value)->firstOrFail();
        JobAlertConfig::create([
            'backup_job_id' => $job->id,
            'alert_rule_id' => $rule->id,
            'enabled' => true,
            'config' => ['backup_size_out_of_range_min_bytes' => 1024],
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->put('/backup-jobs/'.$job->id, [
                'name' => $job->name,
                'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
                'volume_name' => $job->volume_name,
                'backup_destination_id' => $job->backup_destination_id,
                'schedule_type' => $job->schedule_type,
                'schedule_config' => $job->schedule_config,
                'notifications_enabled' => true,
                'notification_channel_ids' => [],
                'use_custom_alert_settings' => false,
                'alert_notifications_enabled' => true,
                'alert_configs' => [[
                    'alert_rule_id' => $rule->id,
                    'enabled' => true,
                    'config' => [
                        'backup_size_out_of_range_min_bytes' => 4096,
                        'backup_size_out_of_range_max_bytes' => 1024,
                    ],
                ]],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('backup-jobs.index'));

        $this->assertFalse($job->fresh()->use_custom_alert_settings);
        $this->assertSame(0, JobAlertConfig::where('backup_job_id', $job->id)->count());
    }

    public function test_enabled_custom_alert_settings_validate_invalid_alert_configs(): void
    {
        app(EnsureAlertRules::class)->handle();
        $job = $this->backupJob();
        $rule = AlertRule::where('type', AlertType::BackupSizeOutOfRange->value)->firstOrFail();

        $this->actingAs(User::factory()->admin()->create())
            ->put('/backup-jobs/'.$job->id, [
                'name' => $job->name,
                'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
                'volume_name' => $job->volume_name,
                'backup_destination_id' => $job->backup_destination_id,
                'schedule_type' => $job->schedule_type,
                'schedule_config' => $job->schedule_config,
                'notifications_enabled' => true,
                'notification_channel_ids' => [],
                'use_custom_alert_settings' => true,
                'alert_notifications_enabled' => true,
                'alert_configs' => [[
                    'alert_rule_id' => $rule->id,
                    'enabled' => true,
                    'config' => [
                        'backup_size_out_of_range_min_bytes' => 4096,
                        'backup_size_out_of_range_max_bytes' => 1024,
                    ],
                ]],
            ])
            ->assertSessionHasErrors('alert_configs.0.config.backup_size_out_of_range_max_bytes');
    }

    private function enabledRule(AlertType $type, array $config = []): AlertRule
    {
        $rule = $this->disabledRule($type);

        $rule->forceFill([
            'enabled' => true,
            'config' => array_replace($rule->config ?? [], $config),
        ])->save();

        return $rule;
    }

    private function disabledRule(AlertType $type): AlertRule
    {
        app(EnsureAlertRules::class)->handle();

        return AlertRule::where('type', $type->value)->firstOrFail();
    }

    private function backupJob(array $attributes = []): BackupJob
    {
        return BackupJob::create([
            'name' => $attributes['name'] ?? 'Nightly',
            'volume_name' => $attributes['volume_name'] ?? 'app_data',
            'backup_destination_id' => $this->destination()->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => $attributes['status'] ?? BackupJob::STATUS_ACTIVE,
            'last_success_at' => $attributes['last_success_at'] ?? null,
            'last_error' => $attributes['last_error'] ?? null,
            'last_error_at' => $attributes['last_error_at'] ?? null,
            'updated_at' => $attributes['updated_at'] ?? now(),
            'use_custom_alert_settings' => $attributes['use_custom_alert_settings'] ?? false,
            'alert_notifications_enabled' => $attributes['alert_notifications_enabled'] ?? true,
        ]);
    }

    private function destination(): BackupDestination
    {
        return BackupDestination::create([
            'name' => 'S3',
            'provider' => BackupDestination::PROVIDER_AWS_S3,
            'bucket' => 'backups',
            'access_key_id' => 'access',
            'secret_access_key' => 'secret',
        ]);
    }

    private function localDestination(string $path, array $settings = []): BackupDestination
    {
        File::ensureDirectoryExists($path);

        return BackupDestination::create([
            'name' => 'Local',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => array_replace([
                'archive_path' => $path,
                'archive_mount_source' => $path,
            ], $settings),
        ]);
    }

    private function storageLimitDirectory(string $name): string
    {
        $path = sys_get_temp_dir().'/volumevault-storage-limit-'.$name;
        File::deleteDirectory($path);
        File::ensureDirectoryExists($path);

        return $path;
    }
}
