<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BackupDestination;
use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Models\DockerVolume;
use App\Models\RestoreRun;
use App\Models\User;
use App\Services\Agents\AgentClient;
use App\Services\Agents\AgentCompatibility;
use App\Services\Agents\AgentLifecycle;
use App\Services\Agents\AgentRegistry;
use App\Services\Agents\AgentState;
use App\Services\Agents\AgentTlsIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AgentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'volumevault.mode' => 'hybrid', 'volumevault.agents.enabled' => true,
            'volumevault.agents.image' => 'ghcr.io/darkdragon14/volumevault-agent:v2.3.0',
            'volumevault.update_check.enabled' => false, 'app.version' => 'v2.3.0',
        ]);
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
        $this->withoutVite();
        $this->withServerVariables(['HTTPS' => 'on']);
    }

    public function test_guests_and_viewers_cannot_change_maintenance_or_read_update_guide(): void
    {
        $host = $this->remote();
        User::factory()->create(['role' => 'admin']);
        $before = $host->getAttributes();
        $this->post('/docker-hosts/'.$host->id.'/maintenance', ['enabled' => true])->assertRedirect('/login');
        $this->get('/docker-hosts/'.$host->id.'/update-guide')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['role' => 'viewer']));
        $this->maintenance($host, true)->assertForbidden();
        $this->getJson('/docker-hosts/'.$host->id.'/update-guide')->assertForbidden();
        $this->assertSame($before, $host->fresh()->getAttributes());
    }

    public function test_maintenance_nonce_is_persisted_idempotent_and_rotated_on_reentry(): void
    {
        $this->admin();
        $host = $this->remote();
        $this->freezeTime();
        $response = $this->maintenance($host, true)->assertOk()->assertJsonPath('maintenance_ready', false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $host->refresh();
        $token = $host->maintenance_token;
        $requested = $host->maintenance_requested_at;
        $this->assertTrue(Str::isUuid($token));
        $this->assertSame(now()->timestamp, $requested->timestamp);
        $this->travel(1)->seconds();
        $this->maintenance($host, true)->assertOk();
        $this->assertSame($token, $host->fresh()->maintenance_token);
        $this->assertTrue($requested->equalTo($host->fresh()->maintenance_requested_at));
        $this->heartbeat($host, ['maintenance_token' => $token])->assertOk()->assertJsonPath('maintenance_token', $token);
        $this->assertTrue($this->state($host)['maintenance_ready']);
        $this->maintenance($host, false)->assertOk()->assertJsonPath('maintenance_requested', false);
        $this->assertNull($host->fresh()->maintenance_token);
        $this->assertNull($host->fresh()->agent_maintenance_token);
        $this->maintenance($host, true)->assertOk();
        $this->assertNotSame($token, $host->fresh()->maintenance_token);
        $this->heartbeat($host, ['maintenance_token' => $token])->assertOk();
        $this->assertFalse($this->state($host)['maintenance_ready']);
    }

    #[DataProvider('unreadyAgents')]
    public function test_remote_readiness_requires_live_compatible_ack_and_explicit_zero(array $changes): void
    {
        $host = $this->readyRemote();
        $host->forceFill($changes)->save();
        $this->assertFalse($this->state($host)['maintenance_ready']);
    }

    public static function unreadyAgents(): array
    {
        return [
            'missing ack' => [['agent_maintenance_token' => null]],
            'stale ack' => [['agent_maintenance_token' => 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa']],
            'no activity report' => [['agent_active_operations' => null]],
            'agent busy' => [['agent_active_operations' => 1]],
            'offline' => [['last_seen_at' => '2000-01-01 00:00:00']],
            'protocol' => [['agent_protocol_version' => 2]],
            'capability' => [['agent_capabilities' => ['maintenance-v1']]],
            'revoked' => [['agent_revoked_at' => '2000-01-01 00:00:00']],
        ];
    }

    public function test_local_maintenance_needs_no_agent_capability_and_orchestrator_refuses_it(): void
    {
        $this->admin();
        $host = DockerHost::findOrFail(DockerHost::LOCAL_ID);
        $this->maintenance($host, true)->assertOk()->assertJsonPath('maintenance_ready', true);
        $this->maintenance($host, false)->assertOk();
        config(['volumevault.mode' => 'orchestrator']);
        $this->maintenance($host, true)->assertUnprocessable();
        $this->assertNull($host->fresh()->maintenance_requested_at);
    }

    public function test_resume_requires_connection_compatibility_and_no_active_operations(): void
    {
        $this->admin();
        $host = $this->readyRemote();
        foreach ([['last_seen_at' => now()->subMinutes(5)], ['agent_protocol_version' => 2], ['agent_active_operations' => 1]] as $changes) {
            $host->forceFill(['last_seen_at' => now(), 'agent_protocol_version' => 1, 'agent_active_operations' => 0, ...$changes])->save();
            $this->maintenance($host, false)->assertConflict();
            $this->assertNotNull($host->fresh()->maintenance_requested_at);
        }
        $host->forceFill(['agent_active_operations' => 0])->save();
        $this->maintenance($host, false)->assertOk()->assertJsonPath('maintenance_requested', false);
    }

    public function test_maintenance_validates_enabled_and_rejects_unregistered_or_revoked_hosts(): void
    {
        $this->admin();
        $host = $this->remote();
        foreach ([[], ['enabled' => 'invalid'], ['enabled' => null]] as $body) {
            $this->postJson('/docker-hosts/'.$host->id.'/maintenance', $body)->assertUnprocessable()->assertJsonValidationErrors('enabled');
        }
        $this->assertNull($host->fresh()->maintenance_requested_at);
        $pending = DockerHost::factory()->create();
        $this->maintenance($pending, true)->assertConflict();
        app(AgentRegistry::class)->revoke($host);
        $this->maintenance($host, true)->assertConflict();
        $this->assertNull($host->fresh()->maintenance_requested_at);
    }

    public function test_heartbeat_validates_lifecycle_fields_and_missing_activity_cannot_acknowledge(): void
    {
        $host = $this->readyRemote();
        $before = $host->getAttributes();
        foreach ([['maintenance_token' => 'invalid'], ['active_operations' => -1], ['active_operations' => 1001], ['active_operations' => 'invalid']] as $changes) {
            $this->heartbeat($host, $changes)->assertUnprocessable()->assertJsonValidationErrors(array_keys($changes));
            $this->assertSame($before, $host->fresh()->getAttributes());
        }
        $this->postJson('https://orchestrator.test/agent/v1/heartbeat', [
            'instance_id' => $host->agent_instance_id, 'protocol_version' => 1, 'version' => '2.2.0',
            'docker_status' => 'ready', 'capabilities' => ['inventory-v1'], 'maintenance_token' => $host->maintenance_token,
        ], ['Authorization' => 'Bearer '.$host->uuid.'.'.str_repeat('a', 64)])->assertOk();
        $this->assertNull($host->fresh()->agent_active_operations);
        $this->assertFalse($this->state($host)['maintenance_ready']);
    }

    public function test_update_guide_is_get_only_download_only_and_preserves_identity(): void
    {
        $this->admin();
        $host = $this->readyRemote();
        $before = $host->getAttributes();
        $logs = ActivityLog::count();
        $this->getJson('/docker-hosts/'.$host->id.'/update-guide')->assertOk()->assertExactJson([
            'image' => config('volumevault.agents.image'), 'version' => 'v2.3.0',
            'container_name' => 'volumevault-agent-'.substr($host->uuid, 0, 8),
            'volume_name' => 'volumevault-agent-'.$host->uuid,
            'command' => "docker pull 'ghcr.io/darkdragon14/volumevault-agent:v2.3.0'",
            'maintenance_ready' => true, 'uses_existing_identity' => true,
        ]);
        $this->postJson('/docker-hosts/'.$host->id.'/update-guide')->assertMethodNotAllowed();
        $this->assertSame($before, $host->fresh()->getAttributes());
        $this->assertSame($logs, ActivityLog::count());
        $this->heartbeat($host)->assertOk();
    }

    public function test_update_guide_rejects_local_pending_revoked_and_unready_agents(): void
    {
        $this->admin();
        $this->getJson('/docker-hosts/'.DockerHost::LOCAL_ID.'/update-guide')->assertUnprocessable();
        foreach ([DockerHost::factory()->create(), $this->remote(), $this->readyRemote()] as $host) {
            if ($host->maintenance_requested_at !== null) {
                app(AgentRegistry::class)->revoke($host);
            }
            $this->getJson('/docker-hosts/'.$host->id.'/update-guide')->assertConflict();
        }
    }

    public function test_reenrollment_preserves_maintenance_but_invalidates_acknowledgment(): void
    {
        $host = $this->readyRemote();
        $token = $host->maintenance_token;
        $requested = $host->maintenance_requested_at;
        $oldInstance = $host->agent_instance_id;
        $this->mock(AgentTlsIdentity::class)->shouldReceive('caCertificate')->andReturn('public-ca');
        $installation = app(AgentRegistry::class)->issueEnrollment($host);
        preg_match('/VOLUMEVAULT_AGENT_ENROLLMENT_TOKEN=([^\x27 ]+)/', $installation['command'], $matches);
        $host->refresh();
        $this->assertSame($token, $host->maintenance_token);
        $this->assertTrue($requested->equalTo($host->maintenance_requested_at));
        $this->assertNull($host->agent_maintenance_token);
        $this->assertFalse($this->state($host)['maintenance_ready']);
        $instance = (string) Str::uuid();
        $this->postJson('https://orchestrator.test/agent/v1/enroll', [
            'protocol_version' => 1, 'version' => '2.2.0', 'instance_id' => $instance, 'credential' => str_repeat('a', 64),
        ], ['Authorization' => 'Bearer '.$matches[1]])->assertOk();
        $host->refresh();
        $this->heartbeat($host, ['instance_id' => $oldInstance, 'maintenance_token' => $token])->assertUnauthorized();
        $this->heartbeat($host)->assertOk()->assertJsonPath('maintenance_token', $token);
        $this->assertFalse($this->state($host)['maintenance_ready']);
        $this->heartbeat($host, ['maintenance_token' => $token])->assertOk();
        $this->assertTrue($this->state($host)['maintenance_ready']);
    }

    #[DataProvider('versions')]
    public function test_version_status_is_relative_to_orchestrator_and_not_protocol_compatibility(?string $installed, string $target, string $expected): void
    {
        config(['app.version' => $target]);
        $host = $this->remote();
        $host->forceFill(['agent_version' => $installed, 'agent_capabilities' => ['inventory-v1']])->save();
        $compatibility = app(AgentCompatibility::class);
        $this->assertSame('compatible', $compatibility->status($host));
        $this->assertSame($expected, $compatibility->updateStatus($installed));
    }

    public static function versions(): array
    {
        return [
            ['2.3.0', 'v2.3.0', 'current'], ['v2.2.9', '2.3.0', 'available'],
            ['2.10.0', '2.3.0', 'ahead'], ['2.3.0-rc.1', '2.3.0', 'available'],
            ['dev', '2.3.0', 'unknown'], ['2.3.0', 'main', 'unknown'], [null, '2.3.0', 'unknown'],
        ];
    }

    #[DataProvider('deploymentModes')]
    public function test_shared_deployment_and_localhost_metrics(string $mode, bool $enabled): void
    {
        $this->admin();
        config(['volumevault.mode' => $mode]);
        DockerVolume::create(['docker_host_id' => DockerHost::LOCAL_ID, 'name' => 'local-data', 'exists' => true]);
        $this->get('/docker-hosts')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('deployment.mode', $mode)->where('deployment.local_execution_enabled', $enabled)
            ->where('hosts.0.role', $mode)->where('hosts.0.local_execution_enabled', $enabled)
            ->where('hosts.0.container_count', null)->where('hosts.0.agent_version', 'v2.3.0')
            ->where('hosts.0.volume_count', $enabled ? 1 : 0));
    }

    public static function deploymentModes(): array
    {
        return [['hybrid', true], ['orchestrator', false]];
    }

    #[DataProvider('unsupportedProtocols')]
    public function test_incompatible_protocol_records_only_authenticated_host_diagnostic(mixed $protocol, ?int $diagnostic): void
    {
        $host = $this->readyRemote();
        $other = $this->readyRemote();
        $otherBefore = $other->getAttributes();
        DockerVolume::create(['docker_host_id' => $host->id, 'name' => 'keep', 'exists' => true]);
        $this->postJson('https://orchestrator.test/agent/v1/inventory', [
            'protocol_version' => $protocol, 'instance_id' => $host->agent_instance_id,
            'version' => '9.0.0', 'host_uuid' => $other->uuid, 'sequence' => 99,
            'volumes' => [], 'containers' => [], 'host_path_allowlist' => [],
        ], ['Authorization' => 'Bearer '.$host->uuid.'.'.str_repeat('a', 64)])
            ->assertConflict()->assertJsonPath('supported_protocols', [1]);
        $host->refresh();
        $this->assertSame($diagnostic, $host->agent_protocol_version);
        $this->assertSame('9.0.0', $host->agent_version);
        $this->assertNull($host->agent_maintenance_token);
        $this->assertNotNull($host->maintenance_requested_at);
        $this->assertSame(0, $host->agent_inventory_sequence);
        $this->assertDatabaseHas('docker_volumes', ['docker_host_id' => $host->id, 'name' => 'keep', 'exists' => true]);
        $this->assertSame($otherBefore, $other->fresh()->getAttributes());
        $before = $host->getAttributes();
        $this->postJson('https://orchestrator.test/agent/v1/heartbeat', ['protocol_version' => $protocol], [
            'Authorization' => 'Bearer '.$host->uuid.'.'.str_repeat('b', 64),
        ])->assertUnauthorized();
        $this->assertSame($before, $host->fresh()->getAttributes());
    }

    public static function unsupportedProtocols(): array
    {
        return [[2, 2], ['1', null], ['malformed', null]];
    }

    public function test_agent_client_echoes_previous_heartbeat_nonce_and_explicit_activity(): void
    {
        $token = (string) Str::uuid();
        $state = $this->mock(AgentState::class);
        $state->shouldReceive('identity')->andReturn([
            'enrolled' => true, 'host_uuid' => (string) Str::uuid(), 'credential' => str_repeat('a', 64),
            'origin' => 'https://orchestrator.test', 'instance_id' => (string) Str::uuid(),
        ]);
        $state->shouldReceive('caPath')->andReturn('/tmp/unused-ca.pem');
        Http::fake(['https://orchestrator.test/*' => Http::sequence()
            ->push(['maintenance_token' => $token])->push(['maintenance_token' => null])->push([])]);
        $client = app(AgentClient::class);
        $client->heartbeat(true, 2);
        $client->heartbeat(true);
        $client->heartbeat(true);
        $requests = Http::recorded();
        $this->assertNull($requests[0][0]['maintenance_token']);
        $this->assertSame(2, $requests[0][0]['active_operations']);
        $this->assertSame($token, $requests[1][0]['maintenance_token']);
        $this->assertSame(0, $requests[1][0]['active_operations']);
        $this->assertNull($requests[2][0]['maintenance_token']);
    }

    #[DataProvider('executorTypes')]
    public function test_central_active_operations_count_cleanup_restore_target_and_accepted_groups_but_not_queued(bool $remote): void
    {
        $this->admin();
        $host = $remote ? $this->readyRemote() : DockerHost::findOrFail(DockerHost::LOCAL_ID);
        $other = $this->remote();
        $destination = BackupDestination::create([
            'name' => 'Local', 'provider' => 'local', 'bucket' => 'local', 'access_key_id' => '',
            'secret_access_key' => '', 'settings' => ['archive_path' => '/tmp/vv'],
        ]);
        $group = BackupJobGroup::create([
            'name' => 'Nightly', 'schedule_type' => 'daily', 'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *', 'status' => 'active',
        ]);
        $job = BackupJob::create([
            'name' => 'Data', 'docker_host_id' => $host->id, 'volume_name' => 'data',
            'backup_destination_id' => $destination->id, 'backup_job_group_id' => $group->id,
            'schedule_type' => 'daily', 'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *', 'status' => 'active',
        ]);
        $backup = BackupRun::create([
            'backup_job_id' => $job->id, 'docker_host_id' => $host->id,
            'source_volume_name' => 'data', 'status' => 'queued', 'trigger' => 'manual',
        ]);
        $restore = RestoreRun::create([
            'backup_job_id' => $job->id, 'backup_destination_id' => $destination->id,
            'source_docker_host_id' => $other->id, 'target_docker_host_id' => $host->id,
            'selected_backup_key' => 'data.tar.gz', 'source_volume_name' => 'data',
            'target_volume_name' => 'restored', 'mode' => RestoreRun::MODE_NEW_VOLUME, 'status' => 'queued',
        ]);
        $groupRun = BackupGroupRun::create(['backup_job_group_id' => $group->id, 'status' => 'queued', 'trigger' => 'manual']);
        $this->maintenance($host, true)->assertOk()->assertJsonPath('maintenance_ready', true)->assertJsonPath('active_operations', 0);
        foreach ([
            ['status' => 'running'], ['status' => 'failed', 'docker_container_cleanup_pending' => true],
            ['status' => 'failed', 'docker_container_cleanup_pending' => false, 'stopped_container_ids' => ['container']],
        ] as $changes) {
            $backup->forceFill($changes)->save();
            $this->assertSame(1, $this->state($host)['active_operations']);
            $this->assertFalse($this->state($host)['maintenance_ready']);
            $this->maintenance($host, false)->assertConflict();
        }
        $backup->forceFill(['stopped_container_ids' => []])->save();
        $restore->update(['status' => 'running']);
        $this->assertSame(1, $this->state($host)['active_operations']);
        $this->assertSame(0, $this->state($other)['active_operations']);
        $restore->forceFill(['status' => 'failed', 'stopped_container_ids' => ['container']])->save();
        $this->assertSame(1, $this->state($host)['active_operations']);
        $restore->forceFill(['stopped_container_ids' => []])->save();
        $groupRun->update(['status' => 'running']);
        $this->assertSame(1, $this->state($host)['active_operations']);
        $this->assertFalse($this->state($host)['maintenance_ready']);
        $backup->update(['backup_group_run_id' => $groupRun->id]);
        $job->update(['docker_host_id' => $other->id]);
        $this->assertSame(1, $this->state($host)['active_operations']);
        $this->assertSame(1, $this->state($other)['active_operations']);
        $groupRun->update(['status' => 'success']);
        $this->assertTrue($this->state($host)['maintenance_ready']);
    }

    public static function executorTypes(): array
    {
        return ['local' => [false], 'remote' => [true]];
    }

    private function admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function remote(): DockerHost
    {
        $host = DockerHost::factory()->create();
        $host->forceFill([
            'agent_registered_at' => now(), 'last_seen_at' => now(),
            'agent_token_hash' => hash('sha256', str_repeat('a', 64)), 'agent_instance_id' => (string) Str::uuid(),
            'agent_protocol_version' => 1, 'agent_capabilities' => ['inventory-v1', 'maintenance-v1'],
            'agent_version' => '2.2.0', 'agent_active_operations' => 0,
        ])->save();

        return $host->refresh();
    }

    private function readyRemote(): DockerHost
    {
        $host = $this->remote();
        $token = (string) Str::uuid();
        $host->forceFill(['maintenance_requested_at' => now(), 'maintenance_token' => $token, 'agent_maintenance_token' => $token])->save();

        return $host->refresh();
    }

    /** @return array{maintenance_requested: bool, maintenance_ready: bool, active_operations: int} */
    private function state(DockerHost $host): array
    {
        return app(AgentLifecycle::class)->state($host->fresh());
    }

    private function maintenance(DockerHost $host, bool $enabled): TestResponse
    {
        return $this->postJson('/docker-hosts/'.$host->id.'/maintenance', ['enabled' => $enabled]);
    }

    private function heartbeat(DockerHost $host, array $changes = []): TestResponse
    {
        return $this->postJson('https://orchestrator.test/agent/v1/heartbeat', [
            'instance_id' => $host->agent_instance_id, 'protocol_version' => 1, 'version' => '2.2.0',
            'docker_status' => 'ready', 'capabilities' => ['inventory-v1', 'maintenance-v1'],
            'active_operations' => 0, ...$changes,
        ], ['Authorization' => 'Bearer '.$host->uuid.'.'.str_repeat('a', 64)]);
    }
}
