<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\DockerHost;
use App\Models\User;
use App\Services\Agents\AgentTlsIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DockerHostManagementTest extends TestCase
{
    use RefreshDatabase;

    private string $tlsDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tlsDirectory = sys_get_temp_dir().'/agent-management-'.Str::uuid();
        config(['volumevault.agents.enabled' => true, 'volumevault.agents.url' => 'https://orchestrator.test:8443', 'volumevault.agents.tls_directory' => $this->tlsDirectory, 'volumevault.update_check.enabled' => false]);
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tlsDirectory);
        parent::tearDown();
    }

    public function test_guests_redirect_and_viewers_cannot_manage_hosts(): void
    {
        $host = DockerHost::factory()->create();
        User::factory()->create(['role' => 'admin']);
        $this->get('/docker-hosts')->assertRedirect('/login');
        $this->post('/docker-hosts', ['name' => 'forbidden'])->assertRedirect('/login');
        $this->post('/docker-hosts/'.$host->id.'/enrollment')->assertRedirect('/login');
        $this->delete('/docker-hosts/'.$host->id.'/agent')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['role' => 'viewer']));
        $this->get('/docker-hosts')->assertForbidden();
        $this->postJson('/docker-hosts', ['name' => 'forbidden'])->assertForbidden();
        $this->postJson('/docker-hosts/'.$host->id.'/enrollment')->assertForbidden();
        $this->deleteJson('/docker-hosts/'.$host->id.'/agent')->assertForbidden();
        $this->assertDatabaseCount('docker_hosts', 2);
        $this->assertNull($host->fresh()->agent_enrollment_hash);
        $this->assertNull($host->fresh()->agent_revoked_at);
    }

    public function test_local_host_refuses_enrollment_and_revocation(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $local = DockerHost::findOrFail(DockerHost::LOCAL_ID);
        $before = $local->getAttributes();
        $this->postJson('/docker-hosts/'.$local->id.'/enrollment')->assertUnprocessable();
        $this->deleteJson('/docker-hosts/'.$local->id.'/agent')->assertUnprocessable();
        $this->assertSame($before, $local->fresh()->getAttributes());
    }

    public function test_disabled_transport_rolls_back_host_creation(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        config(['volumevault.agents.enabled' => false]);
        $this->postJson('/docker-hosts', ['name' => 'disabled'])->assertConflict();
        $this->assertDatabaseCount('docker_hosts', 1);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_create_returns_safe_command_public_ca_and_one_time_secret_without_session_persistence(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $name = 'host\'; $(touch /tmp/never-execute) #';
        $response = $this->postJson('/docker-hosts', ['name' => $name, 'driver' => 'local', 'agent_token_hash' => 'injected'])->assertCreated();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $host = DockerHost::findOrFail($response->json('host.id'));
        $this->assertSame($name, $host->name);
        $this->assertSame(DockerHost::DRIVER_AGENT, $host->driver);
        $this->assertSame('pending', $host->agentStatus());
        $this->assertNull($host->agent_token_hash);
        $command = $response->json('installation.command');
        $this->assertStringNotContainsString($name, $command);
        $this->assertStringContainsString("'--name' 'volumevault-agent-".substr($host->uuid, 0, 8)."'", $command);
        $this->assertStringContainsString("'--no-healthcheck'", $command);
        $this->assertStringContainsString(escapeshellarg('VOLUMEVAULT_ORCHESTRATOR_URL=https://orchestrator.test:8443'), $command);
        $ca = app(AgentTlsIdentity::class)->caCertificate();
        $this->assertStringContainsString(escapeshellarg('VOLUMEVAULT_AGENT_CA='.base64_encode($ca)), $command);
        $this->assertStringNotContainsString('PRIVATE KEY', $command);
        preg_match('/VOLUMEVAULT_AGENT_ENROLLMENT_TOKEN=([^\x27 ]+)/', $command, $matches);
        [$uuid, $secret] = explode('.', $matches[1]);
        $this->assertSame($host->uuid, $uuid);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $secret);
        $this->assertSame(hash('sha256', $secret), $host->agent_enrollment_hash);
        $this->assertEqualsWithDelta(now()->addMinutes(15)->timestamp, strtotime($response->json('installation.expires_at')), 2);
        foreach ([$host->toJson(), json_encode($host->getAttributes()), ActivityLog::all()->toJson(), json_encode(session()->all()), json_encode($response->json('host'))] as $serialized) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
    }

    public function test_issue_revoke_and_reissue_preserve_tls_ca_and_rotate_enrollment(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $created = $this->postJson('/docker-hosts', ['name' => 'remote'])->assertCreated();
        $host = DockerHost::findOrFail($created->json('host.id'));
        $hash = $host->agent_enrollment_hash;
        $tls = app(AgentTlsIdentity::class);
        $ca = $tls->caCertificate();
        $key = file_get_contents($this->tlsDirectory.'/ca.key');
        $this->deleteJson('/docker-hosts/'.$host->id.'/agent')->assertOk()->assertJsonPath('revoked', true);
        $this->assertSame('revoked', $host->fresh()->agentStatus());
        $this->assertNull($host->fresh()->agent_enrollment_hash);
        $renewed = $this->postJson('/docker-hosts/'.$host->id.'/enrollment')->assertOk();
        $this->assertStringContainsString('no-store', $renewed->headers->get('Cache-Control'));
        $this->assertSame('pending', $host->fresh()->agentStatus());
        $this->assertNotSame($hash, $host->fresh()->agent_enrollment_hash);
        $tls->ensure();
        $this->assertSame($ca, $tls->caCertificate());
        $this->assertSame($key, file_get_contents($this->tlsDirectory.'/ca.key'));
        $this->assertStringContainsString(base64_encode($ca), $renewed->json('installation.command'));
    }

    public function test_admin_props_expose_status_and_counts_but_not_inventory_or_identity_secrets(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $host = DockerHost::factory()->create();
        $host->forceFill([
            'agent_enrollment_hash' => str_repeat('a', 64), 'agent_token_hash' => str_repeat('b', 64),
            'agent_instance_id' => (string) Str::uuid(), 'agent_registered_at' => now(), 'last_seen_at' => now(),
            'agent_containers' => [['id' => str_repeat('c', 64), 'names' => 'private-container', 'image' => 'private-registry/internal:secret']],
            'agent_host_path_allowlist' => ['/private/secret-host-path'],
        ])->save();
        $response = $this->get('/docker-hosts')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('DockerHosts/Index')->where('agentsEnabled', true)
            ->where('hosts.0.status', 'local')->where('hosts.1.status', 'online')
            ->where('hosts.1.container_count', 1)->missing('hosts.1.agent_token_hash')
            ->missing('hosts.1.agent_enrollment_hash')->missing('hosts.1.agent_instance_id')
            ->missing('hosts.1.agent_containers')->missing('hosts.1.agent_host_path_allowlist'));
        foreach (['private-container', 'private-registry/internal:secret', '/private/secret-host-path', $host->agent_token_hash, $host->agent_enrollment_hash, $host->agent_instance_id] as $secret) {
            $response->assertDontSee($secret, false);
            $this->assertStringNotContainsString($secret, $host->toJson());
        }
    }
}
