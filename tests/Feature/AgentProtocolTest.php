<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\DockerHost;
use App\Models\DockerVolume;
use App\Models\User;
use App\Services\Agents\AgentRegistry;
use App\Services\Agents\AgentTlsIdentity;
use App\Services\Agents\ReceiveAgentInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AgentProtocolTest extends TestCase
{
    use RefreshDatabase;

    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['volumevault.agents.enabled' => true, 'volumevault.agents.url' => 'https://orchestrator.test:8443', 'volumevault.agents.tls_directory' => sys_get_temp_dir().'/agent-protocol-'.Str::uuid()]);
        $this->mock(AgentTlsIdentity::class)->shouldReceive('caCertificate')->andReturn('test-public-ca');
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
        Log::listen(function (MessageLogged $event): void {
            $this->logs[] = [$event->message, $event->context];
        });
        $this->withServerVariables(['HTTPS' => 'on']);
    }

    public function test_enrollment_is_idempotent_after_response_loss_and_expiry_and_stores_only_hashes(): void
    {
        [$host, $token, $body] = $this->pending();
        $this->assertSame('pending', $host->fresh()->agentStatus());
        $response = $this->sendAgent('enroll', $token, $body)->assertOk()->assertJsonPath('host_uuid', $host->uuid);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->travel(16)->minutes();
        $this->sendAgent('enroll', $token, $body)->assertOk()->assertExactJson($response->json());
        $this->sendAgent('enroll', $token, [...$body, 'instance_id' => (string) Str::uuid()])->assertUnauthorized();
        $this->sendAgent('enroll', $token, [...$body, 'credential' => str_repeat('b', 64)])->assertUnauthorized();
        $host->refresh();
        $secret = explode('.', $token)[1];
        $this->assertSame(hash('sha256', $secret), $host->agent_enrollment_hash);
        $this->assertSame(hash('sha256', $body['credential']), $host->agent_token_hash);
        foreach ([$response->getContent(), $host->toJson(), json_encode($host->getAttributes()), ActivityLog::all()->toJson(), json_encode($this->logs)] as $serialized) {
            $this->assertStringNotContainsString($secret, $serialized);
            $this->assertStringNotContainsString($body['credential'], $serialized);
        }
        $this->assertArrayNotHasKey('agent_token_hash', $host->toArray());
        $this->assertArrayNotHasKey('agent_enrollment_hash', $host->toArray());
        $this->assertSame(1, ActivityLog::where('event_type', 'agent_registered')->count());
    }

    public function test_expired_unused_enrollment_is_rejected(): void
    {
        [$host, $token, $body] = $this->pending();
        $this->travel(15)->minutes();
        $this->sendAgent('enroll', $token, $body)->assertUnauthorized();
        $this->assertNull($host->fresh()->agent_token_hash);
    }

    public function test_plain_http_and_spoofed_forwarded_proto_are_rejected_before_consumption(): void
    {
        [$host, $token, $body] = $this->pending();
        $this->withServerVariables(['HTTPS' => 'off', 'REMOTE_ADDR' => '192.0.2.123']);
        foreach ([[], ['X-Forwarded-Proto' => 'https']] as $headers) {
            $this->postJson('http://orchestrator.test/agent/v1/enroll', $body, ['Authorization' => 'Bearer '.$token, ...$headers])->assertStatus(426);
            $this->assertNull($host->fresh()->agent_registered_at);
        }
        $this->withServerVariables(['HTTPS' => 'on']);
        $this->sendAgent('enroll', $token, $body)->assertOk();
    }

    public function test_disabled_transport_rejects_every_endpoint_without_consuming_enrollment(): void
    {
        [$host, $token, $body] = $this->pending();
        config(['volumevault.agents.enabled' => false]);
        foreach (['enroll', 'heartbeat', 'inventory'] as $endpoint) {
            $this->sendAgent($endpoint, $token, $body)->assertNotFound();
        }
        $this->assertNull($host->fresh()->agent_registered_at);
    }

    public function test_non_json_and_oversized_enrollment_are_rejected_before_consumption(): void
    {
        [$host, $token, $body] = $this->pending();
        $this->post('https://orchestrator.test:8443/agent/v1/enroll', $body, ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])->assertStatus(415);
        $this->sendAgent('enroll', $token, [...$body, 'padding' => str_repeat('x', 2 * 1024 * 1024)])->assertStatus(413);
        $this->assertNull($host->fresh()->agent_registered_at);
        $this->sendAgent('enroll', $token, $body)->assertOk();
    }

    public function test_credentials_are_bound_to_host_and_user_tokens_cannot_authenticate_agents(): void
    {
        [$host, , $body, $token] = $this->registered();
        [$other, , $otherBody] = $this->registered();
        $this->sendAgent('heartbeat', $other->uuid.'.'.$body['credential'], $this->heartbeat($otherBody))->assertUnauthorized();
        $this->sendAgent('inventory', $token, $this->inventory($otherBody))->assertUnauthorized();
        $this->sendAgent('heartbeat', Str::uuid().'.'.$body['credential'], $this->heartbeat($body))->assertUnauthorized();
        $local = DockerHost::findOrFail(DockerHost::LOCAL_ID);
        $local->forceFill(['agent_token_hash' => hash('sha256', $body['credential']), 'agent_registered_at' => now(), 'agent_instance_id' => $body['instance_id']])->save();
        $this->sendAgent('heartbeat', $local->uuid.'.'.$body['credential'], $this->heartbeat($body))->assertUnauthorized();
        $user = User::factory()->create(['role' => 'admin']);
        $userToken = $user->createToken('not-an-agent', ['*'])->plainTextToken;
        $this->sendAgent('heartbeat', $userToken, $this->heartbeat($body))->assertUnauthorized();
        $this->actingAs($user);
        $this->sendAgent('heartbeat', '', $this->heartbeat($body))->assertUnauthorized();
        $this->assertSame(0, $host->fresh()->agent_inventory_sequence);
        $this->assertSame(0, $other->fresh()->agent_inventory_sequence);
    }

    #[DataProvider('invalidEnrollmentBodies')]
    public function test_invalid_enrollment_does_not_consume_token(array $changes, int $status): void
    {
        [$host, $token, $body] = $this->pending();
        $this->sendAgent('enroll', $token, array_replace($body, $changes))->assertStatus($status);
        $this->assertNull($host->fresh()->agent_registered_at);
        $this->sendAgent('enroll', $token, $body)->assertOk();
    }

    public static function invalidEnrollmentBodies(): array
    {
        return [
            'missing instance' => [['instance_id' => null], 422],
            'invalid instance' => [['instance_id' => 'not-a-uuid'], 422],
            'missing credential' => [['credential' => null], 422],
            'short credential' => [['credential' => 'abc'], 422],
            'nonhex credential' => [['credential' => str_repeat('z', 64)], 422],
            'missing version' => [['version' => null], 422],
            'long version' => [['version' => str_repeat('v', 101)], 422],
            'future protocol' => [['protocol_version' => 2], 409],
            'string protocol' => [['protocol_version' => '1'], 409],
            'missing protocol' => [['protocol_version' => null], 409],
        ];
    }

    public function test_malformed_unknown_and_local_host_tokens_are_rejected(): void
    {
        [$host, $token, $body] = $this->pending();
        $local = DockerHost::findOrFail(DockerHost::LOCAL_ID);
        $secret = explode('.', $token)[1];
        $local->forceFill(['agent_enrollment_hash' => hash('sha256', $secret), 'agent_enrollment_expires_at' => now()->addMinute()])->save();
        foreach (['', 'garbage', $token.'.extra', $host->uuid.'.short', Str::uuid().'.'.$secret, $local->uuid.'.'.$secret] as $invalid) {
            $this->sendAgent('enroll', $invalid, $body)->assertUnauthorized();
        }
        $this->assertNull($host->fresh()->agent_registered_at);
        $this->assertNull($local->fresh()->agent_registered_at);
    }

    public function test_authentication_status_transitions_and_old_credentials_after_reissue(): void
    {
        [$host, $enrollment, $body, $token] = $this->registered();
        $this->assertSame('online', $host->fresh()->agentStatus());
        $heartbeat = $this->heartbeat($body);
        $this->sendAgent('heartbeat', $host->uuid.'.'.str_repeat('b', 64), $heartbeat)->assertUnauthorized();
        $this->sendAgent('heartbeat', $enrollment, $heartbeat)->assertUnauthorized();
        $this->sendAgent('heartbeat', $token, [...$heartbeat, 'instance_id' => (string) Str::uuid()])->assertUnauthorized();
        $this->travel(91)->seconds();
        $this->assertSame('offline', $host->fresh()->agentStatus());
        $this->sendAgent('heartbeat', $token, $heartbeat)->assertOk();
        $this->assertSame('online', $host->fresh()->agentStatus());
        app(AgentRegistry::class)->revoke($host);
        $this->assertSame('revoked', $host->fresh()->agentStatus());
        $this->sendAgent('heartbeat', $token, $heartbeat)->assertUnauthorized();
        $this->sendAgent('enroll', $enrollment, $body)->assertUnauthorized();
        $installation = app(AgentRegistry::class)->issueEnrollment($host);
        $this->assertSame('pending', $host->fresh()->agentStatus());
        $this->sendAgent('heartbeat', $token, $heartbeat)->assertUnauthorized();
        $this->sendAgent('enroll', $enrollment, $body)->assertUnauthorized();
        preg_match('/VOLUMEVAULT_AGENT_ENROLLMENT_TOKEN=([^\x27 ]+)/', $installation['command'], $matches);
        $newBody = [...$body, 'instance_id' => (string) Str::uuid(), 'credential' => str_repeat('c', 64)];
        $this->sendAgent('enroll', $matches[1], $newBody)->assertOk();
        $this->sendAgent('heartbeat', $token, $heartbeat)->assertUnauthorized();
        $this->sendAgent('heartbeat', $host->uuid.'.'.$newBody['credential'], $this->heartbeat($newBody))->assertOk();
    }

    public function test_agent_credentials_cannot_authenticate_public_api_or_web_writes(): void
    {
        [, $enrollment, , $token] = $this->registered();
        User::factory()->create(['role' => 'admin']);
        foreach ([$token, $enrollment] as $credential) {
            $headers = ['Authorization' => 'Bearer '.$credential];
            $this->getJson('/api/v1/me', $headers)->assertUnauthorized();
            $this->postJson('/api/v1/backup-jobs', [], $headers)->assertUnauthorized();
            $this->getJson('/docker-hosts', $headers)->assertUnauthorized();
            $this->postJson('/docker-hosts', ['name' => 'forbidden'], $headers)->assertUnauthorized();
            $this->postJson('/volumes/sync', [], $headers)->assertUnauthorized();
        }
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('docker_hosts', 2);
    }

    public function test_inventory_is_host_scoped_and_stale_sequences_do_not_overwrite(): void
    {
        [$host, , $body, $token] = $this->registered();
        $other = DockerHost::factory()->create();
        foreach ([DockerHost::LOCAL_ID, $other->id, $host->id] as $id) {
            DockerVolume::create(['docker_host_id' => $id, 'name' => 'shared', 'driver' => 'original', 'exists' => true]);
        }
        $inventory = $this->inventory($body);
        $inventory['volumes'] = [['name' => 'shared', 'driver' => 'updated']];
        $this->sendAgent('inventory', $token, $inventory)->assertOk()->assertJsonPath('accepted', true);
        foreach ([DockerHost::LOCAL_ID, $other->id] as $id) {
            $this->assertDatabaseHas('docker_volumes', ['docker_host_id' => $id, 'name' => 'shared', 'driver' => 'original', 'exists' => true]);
        }
        foreach ([1, 2] as $sequence) {
            $this->sendAgent('inventory', $token, [...$inventory, 'sequence' => $sequence, 'volumes' => []])->assertOk()->assertJsonPath('accepted', false);
        }
        $this->assertDatabaseHas('docker_volumes', ['docker_host_id' => $host->id, 'name' => 'shared', 'driver' => 'updated', 'exists' => true]);
        $this->sendAgent('inventory', $token, [...$inventory, 'sequence' => 3, 'volumes' => []])->assertOk()->assertJsonPath('accepted', true);
        $this->assertDatabaseHas('docker_volumes', ['docker_host_id' => $host->id, 'name' => 'shared', 'exists' => false]);
        $this->assertDatabaseHas('docker_volumes', ['docker_host_id' => $other->id, 'name' => 'shared', 'exists' => true]);
        $this->assertDatabaseHas('docker_volumes', ['docker_host_id' => DockerHost::LOCAL_ID, 'name' => 'shared', 'exists' => true]);
    }

    public function test_inventory_reports_docker_version_without_changing_software_version_or_other_hosts(): void
    {
        [$host, , $body, $token] = $this->registered();
        $softwareVersion = $host->fresh()->agent_version;
        $inventory = [...$this->inventory($body), 'docker_version' => '28.1.0'];
        $this->sendAgent('inventory', $token, $inventory)->assertOk()->assertJsonPath('accepted', true);
        $this->assertSame('28.1.0', $host->refresh()->docker_version);
        $this->assertSame(count($inventory['containers']), $host->docker_container_count);
        $this->assertSame($softwareVersion, $host->agent_version);
        $this->assertNull(DockerHost::findOrFail(DockerHost::LOCAL_ID)->docker_version);

        $this->sendAgent('inventory', $token, [...$inventory, 'sequence' => 1, 'docker_version' => 'stale'])->assertOk()->assertJsonPath('accepted', false);
        $this->assertSame('28.1.0', $host->refresh()->docker_version);
        unset($inventory['docker_version']);
        $this->sendAgent('inventory', $token, [...$inventory, 'sequence' => 3])->assertOk();
        $this->assertNull($host->refresh()->docker_version);
    }

    #[DataProvider('invalidInventories')]
    public function test_partial_or_invalid_inventory_never_clears_existing_data(string $field, mixed $value, bool $omit): void
    {
        [$host, , $body, $token] = $this->registered();
        DockerVolume::create(['docker_host_id' => $host->id, 'name' => 'keep', 'exists' => true]);
        $inventory = $this->inventory($body);
        if ($omit) {
            unset($inventory[$field]);
        } else {
            $inventory[$field] = $value;
        }
        $this->sendAgent('inventory', $token, $inventory)->assertUnprocessable();
        $this->assertDatabaseHas('docker_volumes', ['docker_host_id' => $host->id, 'name' => 'keep', 'exists' => true]);
        $this->assertSame(0, $host->fresh()->agent_inventory_sequence);
    }

    public static function invalidInventories(): array
    {
        return [
            ['volumes', null, true], ['containers', null, true], ['host_path_allowlist', null, true],
            ['volumes', null, false], ['volumes', [['name' => 'duplicate'], ['name' => 'duplicate']], false],
            ['volumes', [['name' => '../invalid']], false],
            ['volumes', [['name' => 'valid'], ['driver' => 'partial']], false],
            ['volumes', [['name' => 'valid', 'labels' => ['nested' => ['secret']]]], false],
            ['containers', [['id' => str_repeat('a', 64), 'env' => ['PASSWORD=secret']]], false],
            ['host_path_allowlist', ['relative/path'], false], ['sequence', 0, false],
            ['docker_version', str_repeat('v', 101), false], ['docker_version', ['invalid'], false],
        ];
    }

    public function test_docker_unavailable_heartbeat_does_not_mark_volumes_missing(): void
    {
        [$host, , $body, $token] = $this->registered();
        DockerVolume::create(['docker_host_id' => $host->id, 'name' => 'keep', 'exists' => true]);
        $this->sendAgent('heartbeat', $token, [...$this->heartbeat($body), 'docker_status' => 'unavailable'])->assertOk();
        $this->assertSame('unavailable', $host->fresh()->docker_status);
        $this->assertDatabaseHas('docker_volumes', ['docker_host_id' => $host->id, 'name' => 'keep', 'exists' => true]);
    }

    #[DataProvider('identityRaces')]
    public function test_captured_identity_cannot_mutate_after_revoke_or_reenrollment(string $mutation, string $operation): void
    {
        [$host, , $body, $token] = $this->registered();
        $registry = app(AgentRegistry::class);
        $captured = $registry->authenticate($token);
        if ($mutation === 'revoke') {
            $registry->revoke($host);
        } else {
            $installation = $registry->issueEnrollment($host);
            preg_match('/VOLUMEVAULT_AGENT_ENROLLMENT_TOKEN=([^\x27 ]+)/', $installation['command'], $matches);
            $this->sendAgent('enroll', $matches[1], [...$body, 'instance_id' => (string) Str::uuid(), 'credential' => bin2hex(random_bytes(32))])->assertOk();
        }
        $before = $host->fresh()->getAttributes();
        try {
            if ($operation === 'heartbeat') {
                $registry->heartbeat($captured, $this->heartbeat($body));
            } else {
                app(ReceiveAgentInventory::class)->handle($captured, $this->inventory($body));
            }
            $this->fail('A captured identity was accepted after '.$mutation);
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->getStatusCode());
        }
        $this->assertSame($before, $host->fresh()->getAttributes());
    }

    public static function identityRaces(): array
    {
        return [['revoke', 'heartbeat'], ['revoke', 'inventory'], ['reenroll', 'heartbeat'], ['reenroll', 'inventory']];
    }

    public function test_ten_heartbeats_do_not_exhaust_enrollment_from_the_same_ip(): void
    {
        $this->freezeTime();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.123']);
        [, , $body, $token] = $this->registered();

        for ($request = 0; $request < 10; $request++) {
            $this->sendAgent('heartbeat', $token, $this->heartbeat($body))->assertOk();
        }

        [$other, $enrollment, $otherBody] = $this->pending();
        $this->sendAgent('enroll', $enrollment, $otherBody)->assertOk()->assertJsonPath('host_uuid', $other->uuid);
    }

    public function test_agent_traffic_quota_is_shared_across_endpoints_but_isolated_by_authenticated_host(): void
    {
        $this->freezeTime();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.123']);
        [, , $body, $token] = $this->registered();
        [$other, , $otherBody, $otherToken] = $this->registered();

        for ($request = 0; $request < 180; $request++) {
            $this->sendAgent('heartbeat', $token, [
                ...$this->heartbeat($body),
                'host_uuid' => $other->uuid,
                'docker_host' => ['uuid' => $other->uuid],
            ])->assertOk();
        }

        $this->sendAgent('heartbeat', $token, $this->heartbeat($body))->assertTooManyRequests();
        $this->sendAgent('inventory', $token, $this->inventory($body))->assertTooManyRequests();
        $this->sendAgent('heartbeat', $otherToken, $this->heartbeat($otherBody))->assertOk();
        $this->sendAgent('inventory', $otherToken, $this->inventory($otherBody))->assertOk();
    }

    public function test_ten_enrollments_exhaust_only_the_enrollment_quota_for_that_ip(): void
    {
        $this->freezeTime();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.123']);
        [, $enrollment, $body, $token] = $this->registered();

        for ($request = 1; $request < 10; $request++) {
            $this->sendAgent('enroll', $enrollment, $body)->assertOk();
        }

        $this->sendAgent('enroll', $enrollment, $body)->assertTooManyRequests();
        $this->sendAgent('heartbeat', $token, $this->heartbeat($body))->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.124']);
        $this->sendAgent('enroll', $enrollment, $body)->assertOk();
    }

    public function test_unauthorized_traffic_does_not_consume_authenticated_host_quota(): void
    {
        $this->freezeTime();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.123']);
        [$host, , $body, $token] = $this->registered();

        for ($request = 0; $request < 180; $request++) {
            $this->sendAgent('heartbeat', $host->uuid.'.'.str_repeat('z', 64), [
                ...$this->heartbeat($body),
                'host_uuid' => $host->uuid,
                'docker_host' => ['uuid' => $host->uuid],
            ])->assertUnauthorized();
        }

        $this->sendAgent('heartbeat', $token, [
            ...$this->heartbeat($body),
            'instance_id' => (string) Str::uuid(),
        ])->assertUnauthorized();

        for ($request = 0; $request < 180; $request++) {
            $this->sendAgent('heartbeat', $token, $this->heartbeat($body))->assertOk();
        }

        $this->sendAgent('heartbeat', $token, $this->heartbeat($body))->assertTooManyRequests();
    }

    /** @return array{DockerHost, string, array<string, mixed>} */
    private function pending(): array
    {
        $host = DockerHost::factory()->create();
        $installation = app(AgentRegistry::class)->issueEnrollment($host);
        preg_match('/VOLUMEVAULT_AGENT_ENROLLMENT_TOKEN=([^\x27 ]+)/', $installation['command'], $matches);

        return [$host, $matches[1], ['instance_id' => (string) Str::uuid(), 'credential' => bin2hex(random_bytes(32)), 'version' => 'test-1.0', 'protocol_version' => 1]];
    }

    /** @return array{DockerHost, string, array<string, mixed>, string} */
    private function registered(): array
    {
        [$host, $token, $body] = $this->pending();
        $this->sendAgent('enroll', $token, $body)->assertOk();

        return [$host, $token, $body, $host->uuid.'.'.$body['credential']];
    }

    private function sendAgent(string $endpoint, string $token, array $body): TestResponse
    {
        return $this->postJson('https://orchestrator.test:8443/agent/v1/'.$endpoint, $body, ['Authorization' => 'Bearer '.$token]);
    }

    private function heartbeat(array $body): array
    {
        return ['instance_id' => $body['instance_id'], 'version' => 'test-1.1', 'protocol_version' => 1, 'docker_status' => 'ready', 'capabilities' => ['inventory-v1']];
    }

    private function inventory(array $body): array
    {
        return ['instance_id' => $body['instance_id'], 'protocol_version' => 1, 'sequence' => 2, 'volumes' => [], 'containers' => [], 'host_path_allowlist' => []];
    }
}
