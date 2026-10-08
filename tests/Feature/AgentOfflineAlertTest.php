<?php

namespace Tests\Feature;

use App\Actions\Alerts\EnsureAlertRules;
use App\Actions\Alerts\RunAllAlertChecks;
use App\Enums\AlertEventType;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Jobs\RunAlertChecksJob;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\DockerHost;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class AgentOfflineAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_are_enabled_and_preserve_explicit_disable_and_configuration(): void
    {
        $rule = $this->rule();
        $this->assertTrue($rule->enabled);
        $this->assertSame(15, $rule->config['agent_offline_minutes']);
        $this->assertSame(5, $rule->config['check_interval_minutes']);

        $rule->update(['enabled' => false, 'config' => ['agent_offline_minutes' => 30]]);
        $this->host();
        app(EnsureAlertRules::class)->handle();

        $this->assertFalse($rule->fresh()->enabled);
        $this->assertSame(30, $rule->fresh()->config['agent_offline_minutes']);
    }

    public function test_config_defaults_are_used_for_new_rules(): void
    {
        config([
            'volumevault.alerts.defaults.agent_offline_minutes' => 20,
            'volumevault.alerts.defaults.agent_offline_check_interval_minutes' => 2,
        ]);

        $rule = $this->rule();
        $this->assertSame(20, $rule->config['agent_offline_minutes']);
        $this->assertSame(2, $rule->config['check_interval_minutes']);
    }

    public function test_threshold_boundary_and_recovery(): void
    {
        $this->freezeTime();
        $rule = $this->rule();
        $host = $this->host(['last_seen_at' => now()->subMinutes(15)->addSecond()]);
        $checks = app(RunAllAlertChecks::class);

        $checks->handle($rule);
        $this->assertSame(0, Alert::count());
        $this->travel(1)->seconds();
        $checks->handle($rule);

        $alert = Alert::firstOrFail();
        $this->assertSame($host->id, $alert->subject_id);
        $this->assertSame(AlertStatus::Active, $alert->status);
        $this->assertSame(15, $alert->context['threshold_minutes']);

        $host->forceFill(['last_seen_at' => now()])->save();
        $checks->handle($rule);
        $this->assertSame(AlertStatus::Resolved, $alert->fresh()->status);
        $this->assertDatabaseHas('alert_events', ['alert_id' => $alert->id, 'event_type' => AlertEventType::Resolved->value]);

        $this->travel(15)->minutes();
        $checks->handle($rule);
        $this->assertSame(1, Alert::count());
        $this->assertSame(AlertStatus::Active, $alert->fresh()->status);
    }

    public function test_excluded_hosts_do_not_trigger_and_registration_provides_missing_heartbeat_grace(): void
    {
        $rule = $this->rule();
        $this->host(['driver' => DockerHost::DRIVER_LOCAL]);
        $this->host(['agent_revoked_at' => now()]);
        $this->host(['agent_registered_at' => null]);
        $this->host(['maintenance_requested_at' => now()]);
        $this->host(['last_seen_at' => now()]);
        $this->host(['agent_registered_at' => now(), 'last_seen_at' => null]);

        app(RunAllAlertChecks::class)->handle($rule);
        $this->assertSame(0, Alert::count());

        $host = $this->host(['last_seen_at' => null]);
        app(RunAllAlertChecks::class)->handle($rule);
        $this->assertSame($host->id, Alert::firstOrFail()->subject_id);
    }

    public function test_disabled_rule_does_not_trigger_and_maintenance_resolves_existing_alert(): void
    {
        $rule = $this->rule();
        $host = $this->host();
        $rule->update(['enabled' => false]);
        app(RunAllAlertChecks::class)->handle($rule);
        $this->assertSame(0, Alert::count());

        $rule->update(['enabled' => true]);
        app(RunAllAlertChecks::class)->handle($rule);
        $host->forceFill(['maintenance_requested_at' => now()])->save();
        app(RunAllAlertChecks::class)->handle($rule);
        $this->assertSame(AlertStatus::Resolved, Alert::firstOrFail()->status);
    }

    public function test_rule_channels_receive_deduplicated_alert_reminder_and_recovery(): void
    {
        $this->freezeTime();
        $rule = $this->rule(['reminder_enabled' => true, 'cooldown_minutes' => 60]);
        $host = $this->host();
        $channel = NotificationChannel::create([
            'name' => 'Agent alerts',
            'service' => NotificationChannel::SERVICE_WEBHOOK,
            'url' => json_encode(['fail' => 'generic+https://example.com/fail', 'success' => 'generic+https://example.com/recovered']),
            'notification_level' => NotificationChannel::LEVEL_ERROR,
        ]);
        $rule->notificationChannels()->attach($channel);
        $inactive = NotificationChannel::create([
            'name' => 'Inactive', 'service' => NotificationChannel::SERVICE_ADVANCED,
            'url' => 'ntfy://ntfy.sh/inactive', 'is_active' => false,
        ]);
        $rule->notificationChannels()->attach($inactive);

        $messages = [];
        $process = Mockery::mock(DockerProcess::class);
        $process->shouldReceive('run')->times(3)->andReturnUsing(function ($command, $timeout, $environment) use (&$messages): DockerProcessResult {
            $messages[] = ['url' => $environment['SHOUTRRR_URL'], 'message' => $command[array_search('--message', $command, true) + 1]];

            return new DockerProcessResult([], 0, 'ok', '');
        });
        $this->app->instance(DockerProcess::class, $process);
        $checks = app(RunAllAlertChecks::class);
        $checks->handle($rule);
        $checks->handle($rule);
        $this->assertCount(1, $messages);
        $this->travel(60)->minutes();
        $checks->handle($rule);
        $this->assertCount(2, $messages);
        $host->forceFill(['last_seen_at' => now()])->save();
        $checks->handle($rule);
        $checks->handle($rule);

        $this->assertSame(1, Alert::count());
        $this->assertSame('generic+https://example.com/fail', $messages[0]['url']);
        $this->assertStringContainsString('Agent: '.$host->name, $messages[0]['message']);
        $this->assertSame('generic+https://example.com/recovered', $messages[2]['url']);
        $this->assertStringContainsString('Alert condition is resolved.', $messages[2]['message']);
        $this->assertDatabaseHas('alert_events', ['event_type' => AlertEventType::ReminderSent->value]);
    }

    public function test_queue_checks_honor_interval_and_work_without_channels(): void
    {
        $this->freezeTime();
        config(['volumevault.alerts.enabled' => true]);
        $host = $this->host();
        $job = new RunAlertChecksJob;
        $job->handle(app(EnsureAlertRules::class), app(RunAllAlertChecks::class));
        $alert = Alert::firstOrFail();
        $this->assertNull($alert->last_notified_at);
        $host->forceFill(['last_seen_at' => now()])->save();
        $job->handle(app(EnsureAlertRules::class), app(RunAllAlertChecks::class));
        $this->assertSame(AlertStatus::Active, $alert->fresh()->status);
        $this->travel(5)->minutes();
        $job->handle(app(EnsureAlertRules::class), app(RunAllAlertChecks::class));
        $this->assertSame(AlertStatus::Resolved, $alert->fresh()->status);
    }

    public function test_settings_validate_and_persist_threshold_channels_and_disable(): void
    {
        $rule = $this->rule();
        $this->actingAs(User::factory()->admin()->create())->get(route('alerts.settings.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Alerts/Settings')->has('rules', 6));
        $payload = ['rules' => [[
            'id' => $rule->id, 'enabled' => false, 'notification_channel_ids' => [],
            'config' => [...$rule->config, 'agent_offline_minutes' => 0],
        ]]];
        $this->put(route('alerts.settings.update'), $payload)->assertSessionHasErrors('rules.0.config.agent_offline_minutes');

        $channel = NotificationChannel::create(['name' => 'Agents', 'service' => NotificationChannel::SERVICE_ADVANCED, 'url' => 'ntfy://ntfy.sh/agents']);
        $payload['rules'][0]['config']['agent_offline_minutes'] = 25;
        $payload['rules'][0]['notification_channel_ids'] = [$channel->id];
        $this->put(route('alerts.settings.update'), $payload)->assertSessionHasNoErrors();
        app(EnsureAlertRules::class)->handle();
        $this->assertFalse($rule->fresh()->enabled);
        $this->assertSame(25, $rule->fresh()->config['agent_offline_minutes']);
        $this->assertSame([$channel->id], $rule->notificationChannels()->pluck('notification_channels.id')->all());
    }

    public function test_agent_alert_is_visible_and_filterable_without_exposing_host_credentials(): void
    {
        $rule = $this->rule();
        $host = $this->host(['agent_token_hash' => 'private-token-hash']);
        app(RunAllAlertChecks::class)->handle($rule);

        $this->actingAs(User::factory()->create())->get(route('alerts.index', ['type' => 'agent_offline']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Alerts/Index')
                ->has('alerts.data', 1)
                ->where('alerts.data.0.type', 'agent_offline')
                ->where('alerts.data.0.subject.type', 'DockerHost')
                ->where('alerts.data.0.subject.name', $host->name)
                ->missing('alerts.data.0.subject.agent_token_hash')
                ->missing('alerts.data.0.context.agent_token_hash'));
    }

    public function test_agent_offline_rule_is_not_offered_as_a_backup_job_override(): void
    {
        config(['volumevault.mode' => 'orchestrator']);
        $this->actingAs(User::factory()->admin()->create())->get(route('backup-jobs.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('BackupJobs/Form')
                ->has('alertRules', 4)
                ->where('alertRules', fn ($rules): bool => ! in_array('agent_offline', collect($rules)->pluck('type')->all(), true)));
    }

    private function rule(array $config = []): AlertRule
    {
        app(EnsureAlertRules::class)->handle();
        $rule = AlertRule::where('type', AlertType::AgentOffline->value)->firstOrFail();
        $rule->update(['config' => [...$rule->config, ...$config]]);

        return $rule;
    }

    private function host(array $attributes = []): DockerHost
    {
        return DockerHost::factory()->create([
            'agent_registered_at' => now()->subHour(),
            'last_seen_at' => now()->subMinutes(20),
            ...$attributes,
        ]);
    }
}
