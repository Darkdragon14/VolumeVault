<?php

namespace Tests\Feature;

use App\Actions\Docker\CollectAgentInventory;
use App\Services\Agents\AgentClient;
use App\Services\Agents\AgentCompatibility;
use App\Services\Agents\AgentLabelInventory;
use App\Services\Agents\AgentLoop;
use App\Services\Agents\AgentOperationEnvelope;
use App\Services\Agents\AgentState;
use App\Services\Agents\AgentTlsIdentity;
use App\Services\BackupSources\HostPathPolicy;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AgentClientTest extends TestCase
{
    private string $directory;

    private string $host;

    private string $token;

    private array $states = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/volumevault-client-'.bin2hex(random_bytes(8));
        $this->host = (string) Str::uuid();
        $this->token = $this->host.'.'.bin2hex(random_bytes(32));
        config([
            'volumevault.agents.enabled' => true,
            'volumevault.agents.url' => 'https://vault.example:8443',
            'volumevault.agents.tls_directory' => $this->directory.'/tls',
            'volumevault.agents.client.url' => 'https://vault.example:8443',
            'volumevault.agents.client.enrollment_token' => $this->token,
            'volumevault.agents.client.state_directory' => $this->directory.'/state',
            'app.key' => null,
            'volumevault.host_path_allowlist' => ['/srv/data'],
        ]);
        config(['volumevault.agents.client.ca_certificate' => base64_encode((new AgentTlsIdentity)->caCertificate())]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        foreach ($this->states as $state) {
            $state->close();
        }
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function state(): AgentState
    {
        $state = new AgentState;
        $this->states[] = $state;
        $state->open();

        return $state;
    }

    private function enrollment(): array
    {
        return ['host_uuid' => $this->host, 'protocol_version' => 1, 'heartbeat_interval' => 30, 'inventory_interval' => 300];
    }

    private function fakeServer(): void
    {
        $this->resetHttp();
        Http::fake(['*/enroll' => Http::response($this->enrollment()), '*' => Http::response([], 200)]);
    }

    private function resetHttp(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    private function dockerAvailable(): void
    {
        $collector = $this->mock(CollectAgentInventory::class);
        $collector->shouldReceive('available')->andReturnTrue();
        $collector->shouldReceive('handle')->andReturn([
            'docker_version' => '29.0.0',
            'volumes' => [['name' => 'data', 'driver' => 'local', 'mountpoint' => '/var/lib/docker/volumes/data/_data', 'labels' => [], 'options' => []]],
            'containers' => [['id' => 'abc', 'names' => 'web', 'image' => 'nginx', 'state' => 'running', 'status' => 'Up']],
        ]);
    }

    public function test_once_enrolls_heartbeats_and_publishes_inventory_without_database_or_app_key(): void
    {
        DB::shouldReceive('connection')->never();
        $this->fakeServer();
        $this->dockerAvailable();
        $this->artisan('volumevault:agent --once')->assertSuccessful();
        Http::assertSentCount(3);
        $persisted = json_decode(file_get_contents($this->directory.'/state/state.json'), true);
        $this->assertTrue($persisted['enrolled']);
        $this->assertArrayNotHasKey('enrollment_token', $persisted);
        $this->assertStringNotContainsString($this->token, file_get_contents($this->directory.'/state/state.json'));
        $this->assertSame(0700, fileperms($this->directory.'/state') & 0777);
        foreach (['state.json', 'agent.lock', 'server-ca.pem'] as $file) {
            $this->assertSame(0600, fileperms($this->directory.'/state/'.$file) & 0777);
        }
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/inventory')
            && $request['sequence'] === 1 && $request['protocol_version'] === 1
            && $request['volumes'][0]['name'] === 'data' && $request['containers'][0]['id'] === 'abc'
            && $request['host_path_allowlist'] === ['/srv/data'] && $request['docker_version'] === '29.0.0'
            && $request->hasHeader('Authorization', 'Bearer '.$this->host.'.'.$persisted['credential']));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/heartbeat') && $request['docker_status'] === 'ready' && $request['capabilities'] === AgentCompatibility::CAPABILITIES);
    }

    public function test_final_transport_budget_includes_host_paths_and_preserves_ordinary_inventory(): void
    {
        $this->fakeServer();
        $client = new AgentClient($this->state());
        $client->enroll();
        $containers = [];
        $labels = [];
        foreach (range(1, 120) as $index) {
            $id = hash('sha256', (string) $index);
            $containers[] = ['id' => $id, 'names' => 'app-'.$index];
            $labels[] = ['id' => $id, 'name' => 'app-'.$index, 'created' => '2026-09-01T00:00:00Z', 'running' => true, 'mounts' => [],
                'labels' => ['dev.darkdragon14.volumevault.backup.include-paths' => str_repeat('x', 16000)]];
        }
        $snapshot = ['complete' => true, 'containers' => $labels];
        $this->assertTrue(AgentLabelInventory::bounded(['containers' => $containers, 'label_inventory' => $snapshot])['label_inventory']['complete']);
        $paths = array_fill(0, 100, '/'.str_repeat('p', 3999));
        $volumes = [['name' => 'data', 'driver' => 'local']];
        $client->inventory($volumes, $containers, $paths, '29.0.0', $snapshot);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/inventory')
            && $request['label_inventory'] === ['complete' => false, 'containers' => []]
            && $request['volumes'] === $volumes && $request['containers'] === $containers
            && $request['host_path_allowlist'] === $paths && strlen($request->body()) < 2 * 1024 * 1024);
    }

    public function test_transport_options_enforce_ca_hostname_no_redirects_no_proxy_and_timeouts(): void
    {
        $state = $this->state();
        Http::fake(function (Request $request, array $options) use ($state) {
            $this->assertSame($state->caPath(), $options['verify']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame('', $options['proxy']);
            $this->assertEquals(5, $options['connect_timeout']);
            $this->assertEquals(20, $options['timeout']);
            $this->assertTrue($options['stream_context']['ssl']['verify_peer']);
            $this->assertTrue($options['stream_context']['ssl']['verify_peer_name']);
            $this->assertSame(2, $options['curl'][CURLOPT_SSL_VERIFYHOST]);
            $this->assertSame(CURL_SSLVERSION_TLSv1_2, $options['curl'][CURLOPT_SSLVERSION]);
            $this->assertTrue($request->hasHeader('Content-Type', 'application/json'));
            $persisted = json_decode(file_get_contents($this->directory.'/state/state.json'), true);
            $this->assertSame($persisted['credential'], $request['credential']);
            $this->assertSame($persisted['instance_id'], $request['instance_id']);

            return Http::response($this->enrollment());
        });
        (new AgentClient($state))->enroll();
    }

    public function test_lost_enrollment_response_retries_same_durable_identity_then_restart_uses_credential(): void
    {
        Http::fake(['*' => Http::failedConnection('private error')]);
        $state = $this->state();
        $pending = $state->identity();
        try {
            (new AgentClient($state))->enroll();
            $this->fail('Expected connection failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Agent connection failed.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $state->close();
        $state = $this->state();
        $this->assertSame($pending, $state->identity());
        $this->fakeServer();
        (new AgentClient($state))->enroll();
        Http::assertSent(fn (Request $request) => $request['credential'] === $pending['credential'] && $request['instance_id'] === $pending['instance_id']);
        $state->close();
        config(['volumevault.agents.client.enrollment_token' => '']);
        $state = $this->state();
        $this->resetHttp();
        Http::fake(['*' => Http::response([], 200)]);
        $client = new AgentClient($state);
        $client->enroll();
        $client->heartbeat(true);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/heartbeat') && $request->hasHeader('Authorization', 'Bearer '.$this->host.'.'.$pending['credential']));
    }

    public function test_sequences_are_durable_even_when_inventory_delivery_fails(): void
    {
        $this->fakeServer();
        $state = $this->state();
        $client = new AgentClient($state);
        $client->enroll();
        $client->inventory([], [], []);
        $this->resetHttp();
        Http::fake(['*' => Http::response('private error', 503)]);
        try {
            $client->inventory([], [], []);
            $this->fail('Expected failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Agent request failed (HTTP 503).', $exception->getMessage());
        }
        $state->close();
        $state = $this->state();
        $this->assertSame(2, $state->identity()['sequence']);
        $this->fakeServer();
        (new AgentClient($state))->inventory([], [], []);
        Http::assertSent(fn (Request $request) => $request['sequence'] === 3);
    }

    #[DataProvider('invalidOrigins')]
    public function test_unsafe_origins_are_refused(string $origin): void
    {
        config(['volumevault.agents.client.url' => $origin]);
        $this->expectException(RuntimeException::class);
        $this->state();
    }

    public static function invalidOrigins(): array
    {
        return array_map(fn (string $url): array => [$url], ['http://vault.example', 'https://user:pass@vault.example', 'https://vault.example/agent', 'https://vault.example?secret=x', 'https://vault.example#fragment', 'https://vault.example\\evil', 'https://']);
    }

    #[DataProvider('trustChanges')]
    public function test_existing_trust_is_immutable(string $change): void
    {
        $state = $this->state();
        $before = file_get_contents($this->directory.'/state/state.json');
        $state->close();
        if ($change === 'origin') {
            config(['volumevault.agents.client.url' => 'https://different.example']);
        } elseif ($change === 'host') {
            config(['volumevault.agents.client.enrollment_token' => Str::uuid().'.'.bin2hex(random_bytes(32))]);
        } else {
            config(['volumevault.agents.tls_directory' => $this->directory.'/other-tls']);
            config(['volumevault.agents.client.ca_certificate' => base64_encode((new AgentTlsIdentity)->caCertificate())]);
        }
        $this->expectException(RuntimeException::class);
        try {
            $this->state();
        } finally {
            $this->assertSame($before, file_get_contents($this->directory.'/state/state.json'));
            Http::assertNothingSent();
        }
    }

    public static function trustChanges(): array
    {
        return [['origin'], ['host'], ['ca']];
    }

    public function test_new_enrollment_token_for_same_host_resets_identity_and_sequence(): void
    {
        $this->fakeServer();
        $state = $this->state();
        $before = $state->identity();
        (new AgentClient($state))->enroll();
        $state->nextSequence();
        $state->close();
        config(['volumevault.agents.client.enrollment_token' => $this->host.'.'.bin2hex(random_bytes(32))]);
        $state = $this->state();
        $this->assertNotSame($before['instance_id'], $state->identity()['instance_id']);
        $this->assertNotSame($before['credential'], $state->identity()['credential']);
        $this->assertSame(0, $state->identity()['sequence']);
        $this->assertFalse($state->identity()['enrolled']);
    }

    public function test_revocation_does_not_fall_back_to_enrollment(): void
    {
        $this->fakeServer();
        $state = $this->state();
        (new AgentClient($state))->enroll();
        $state->close();
        $this->dockerAvailable();
        $this->resetHttp();
        Http::fake(['*' => Http::response('secret', 401)]);
        $this->artisan('volumevault:agent --once')->assertFailed();
        $this->artisan('volumevault:agent --once')->assertFailed();
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/enroll'));
    }

    public function test_docker_failure_sends_unavailable_heartbeat_without_inventory(): void
    {
        $this->fakeServer();
        $collector = $this->mock(CollectAgentInventory::class);
        $collector->shouldReceive('available')->andReturnFalse();
        $collector->shouldNotReceive('handle');
        $this->artisan('volumevault:agent --once')->assertFailed();
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/heartbeat') && $request['docker_status'] === 'unavailable');
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/inventory'));
        $this->assertSame(0, json_decode(file_get_contents($this->directory.'/state/state.json'), true)['sequence']);
    }

    public function test_failed_collection_preserves_previous_inventory(): void
    {
        $this->fakeServer();
        $collector = $this->mock(CollectAgentInventory::class);
        $collector->shouldReceive('available')->andReturnTrue();
        $collector->shouldReceive('handle')->andThrow(new RuntimeException('secret Docker output'));
        $this->artisan('volumevault:agent --once')->assertFailed();
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/heartbeat') && $request['docker_status'] === 'unavailable');
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/inventory'));
    }

    public function test_inventory_is_not_republished_before_interval_and_stop_avoids_new_cycle(): void
    {
        $this->fakeServer();
        $collector = $this->mock(CollectAgentInventory::class);
        $collector->shouldReceive('available')->twice()->andReturnTrue();
        $collector->shouldReceive('handle')->once()->andReturn(['volumes' => [], 'containers' => []]);
        $loop = app()->makeWith(AgentLoop::class, ['client' => new AgentClient($this->state())]);
        $loop->cycle();
        $loop->cycle();
        Http::assertSentCount(4);
        $loop->stop();
        $this->assertSame(0, $loop->run(false, fn () => $this->fail('Unexpected failure.')));
        Http::assertSentCount(4);
    }

    public function test_acknowledgement_publishes_idle_state_before_pulling_the_next_operation(): void
    {
        $active = 1;
        $advertised = null;
        $events = [];
        $receipt = ['id' => (string) Str::uuid(), 'token' => str_repeat('a', 64), 'result' => ['status' => 'success']];
        $next = ['id' => (string) Str::uuid()];
        $client = $this->mock(AgentClient::class);
        $client->shouldReceive('enroll')->once();
        $client->shouldReceive('heartbeat')->twice()->andReturnUsing(function (bool $available, int $count) use (&$advertised, &$events): void {
            $this->assertTrue($available);
            $advertised = $count;
            $events[] = 'heartbeat:'.$count;
        });
        $client->shouldReceive('completeOperation')->once()->with($receipt)->andReturnUsing(function () use (&$events): void {
            $events[] = 'complete';
        });
        $client->shouldReceive('pullOperation')->once()->andReturnUsing(function () use (&$advertised, &$events, $next): ?array {
            $events[] = 'pull';

            return $advertised === 0 ? $next : null;
        });
        $client->shouldReceive('inventory')->once();
        $supervisor = $this->mock(\App\Services\Agents\AgentOperationSupervisor::class);
        $supervisor->shouldReceive('tick')->twice();
        $supervisor->shouldReceive('activeCount')->andReturnUsing(function () use (&$active): int {
            return $active;
        });
        $supervisor->shouldReceive('current')->twice()->andReturnNull();
        $supervisor->shouldReceive('pendingResult')->once()->andReturn($receipt);
        $supervisor->shouldReceive('acknowledge')->once()->with($receipt['id'])->andReturnUsing(function () use (&$active, &$events): void {
            $active = 0;
            $events[] = 'acknowledge';
        });
        $supervisor->shouldReceive('accept')->once()->with($next)->andReturnUsing(function () use (&$events): void {
            $events[] = 'accept';
        });
        $collector = $this->mock(CollectAgentInventory::class);
        $collector->shouldReceive('available')->once()->andReturnTrue();
        $collector->shouldReceive('handle')->once()->andReturn(['volumes' => [], 'containers' => []]);
        $loop = new AgentLoop($client, $collector, app(HostPathPolicy::class), $supervisor);
        $this->assertTrue($loop->cycle());
        $this->assertSame(['heartbeat:1', 'complete', 'acknowledge', 'heartbeat:0', 'pull', 'accept'], $events);
    }

    public function test_slow_inventory_keeps_sending_heartbeats_before_the_offline_threshold(): void
    {
        $heartbeatTimes = [];
        $loop = null;
        Http::fake(function (Request $request) use (&$loop, &$heartbeatTimes) {
            if (str_ends_with($request->url(), '/enroll')) {
                return Http::response($this->enrollment());
            }
            if (str_ends_with($request->url(), '/heartbeat')) {
                $heartbeatTimes[] = $loop->time;
            }

            return Http::response([]);
        });
        $collector = $this->mock(CollectAgentInventory::class);
        $collector->shouldReceive('available')->twice()->andReturnTrue();
        $collector->shouldReceive('handle')->once()->andReturnUsing(function (callable $progress) use (&$loop): array {
            foreach ([30, 60, 90, 120] as $time) {
                $loop->time = $time;
                $progress();
            }

            return ['volumes' => [], 'containers' => []];
        });
        $loop = $this->timedLoop($collector);

        $this->assertTrue($loop->cycle());
        $this->assertSame([0.0, 30.0, 60.0, 90.0, 120.0], $heartbeatTimes);
        $loop->time = 150;
        $this->assertTrue($loop->cycle());
        Http::assertSentCount(8);
    }

    public function test_collection_exceeding_total_budget_is_not_published_or_immediately_retried(): void
    {
        $this->fakeServer();
        $loop = null;
        $collector = $this->mock(CollectAgentInventory::class);
        $collector->shouldReceive('available')->twice()->andReturnTrue();
        $collector->shouldReceive('handle')->once()->andReturnUsing(function (callable $progress) use (&$loop): array {
            $loop->time = 300;
            $progress();
            $this->fail('Collection must stop at the global deadline.');
        });
        $loop = $this->timedLoop($collector);

        $this->assertFalse($loop->cycle());
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/heartbeat') && $request['docker_status'] === 'unavailable');
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/inventory'));
        $loop->time = 330;
        $this->assertTrue($loop->cycle());
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/inventory'));
    }

    public function test_heartbeat_transport_failure_during_collection_is_not_misreported_as_docker_failure(): void
    {
        $heartbeats = 0;
        Http::fake(function (Request $request) use (&$heartbeats) {
            if (str_ends_with($request->url(), '/enroll')) {
                return Http::response($this->enrollment());
            }

            return Http::response([], ++$heartbeats === 1 ? 200 : 503);
        });
        $loop = null;
        $collector = $this->mock(CollectAgentInventory::class);
        $collector->shouldReceive('available')->once()->andReturnTrue();
        $collector->shouldReceive('handle')->once()->andReturnUsing(function (callable $progress) use (&$loop): array {
            $loop->time = 30;
            $progress();
            $this->fail('Collection must be interrupted when its heartbeat fails.');
        });
        $loop = $this->timedLoop($collector);
        $this->expectExceptionMessage('Agent request failed (HTTP 503).');
        try {
            $loop->cycle();
        } finally {
            Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/inventory') || ($request->data()['docker_status'] ?? null) === 'unavailable');
        }
    }

    private function timedLoop(CollectAgentInventory $collector): AgentLoop
    {
        return new class(new AgentClient($this->state()), $collector, app(HostPathPolicy::class)) extends AgentLoop
        {
            public float $time = 0;

            protected function monotonicTime(): float
            {
                return $this->time;
            }
        };
    }

    public function test_two_processes_cannot_share_state(): void
    {
        $first = $this->state();
        try {
            $this->state();
            $this->fail('Expected exclusive lock refusal.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Agent state is in use or inaccessible.', $exception->getMessage());
        }
        $first->close();
        $this->assertNotEmpty($this->state()->identity()['instance_id']);
    }

    public function test_persistence_failure_exits_continuous_loop_and_restart_keeps_identity(): void
    {
        $this->fakeServer();
        $this->dockerAvailable();
        $state = $this->state();
        (new AgentClient($state))->enroll();
        $identity = $state->identity();
        $path = $this->directory.'/state/state.json';
        rename($path, $path.'.saved');
        mkdir($path);
        $loop = app()->makeWith(AgentLoop::class, ['client' => new AgentClient($state)]);
        $errors = [];

        $code = $loop->run(false, function (string $message) use ($loop, &$errors): void {
            $errors[] = $message;
            $loop->stop();
        });

        $this->assertSame(1, $code);
        $this->assertSame(['Agent state is unavailable; exiting to reload the persisted identity.'], $errors);
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/inventory'));
        rmdir($path);
        rename($path.'.saved', $path);
        $restarted = $this->state();
        $this->assertSame($identity, $restarted->identity());
        $this->assertSame(0, app()->makeWith(AgentLoop::class, ['client' => new AgentClient($restarted)])->run(true, fn () => $this->fail('Restart should recover.')));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/inventory') && $request['sequence'] === 1);
    }

    public function test_enrollment_response_cannot_switch_host_identity(): void
    {
        Http::fake(['*' => Http::response(array_merge($this->enrollment(), ['host_uuid' => (string) Str::uuid()]))]);
        $state = $this->state();
        $before = $state->identity();
        $this->expectExceptionMessage('Invalid agent enrollment response.');
        try {
            (new AgentClient($state))->enroll();
        } finally {
            $this->assertSame($before, $state->identity());
            $this->assertFalse($state->identity()['enrolled']);
        }
    }

    public function test_redirect_is_a_failure_without_following_location(): void
    {
        Http::fake(['*' => Http::response('sensitive response', 302, ['Location' => 'https://other.example/collect'])]);
        $state = $this->state();
        $this->expectExceptionMessage('Agent request failed (HTTP 302).');
        try {
            (new AgentClient($state))->enroll();
        } finally {
            Http::assertSentCount(1);
            $this->assertFalse($state->identity()['enrolled']);
        }
    }

    public function test_protocol_mismatch_is_reported_without_logging_the_server_body(): void
    {
        Http::fake(['*' => Http::response(['message' => 'sensitive response'], 409)]);
        $this->artisan('volumevault:agent --once')
            ->expectsOutputToContain('Agent protocol is incompatible')
            ->doesntExpectOutputToContain('sensitive response')
            ->assertFailed();
    }

    public function test_maintenance_does_not_prevent_redelivery_of_a_previously_assigned_operation(): void
    {
        $state = $this->state();
        $operation = ['id' => (string) Str::uuid(), 'token' => bin2hex(random_bytes(32)), 'kind' => 'backup', 'spec' => ['version' => 1]];
        $envelope = (new AgentOperationEnvelope)->seal($operation, $this->host.'.'.$state->identity()['credential']);
        Http::fake([
            '*/enroll' => Http::response($this->enrollment()),
            '*/heartbeat' => Http::response(['operations_supported' => true, 'maintenance_token' => (string) Str::uuid()]),
            '*/operations/pull' => Http::response(['operation' => $envelope]),
        ]);
        $client = new AgentClient($state);
        $client->enroll();
        $client->heartbeat(true);
        $this->assertSame($operation, $client->pullOperation());
    }

    public function test_leaf_certificate_cannot_be_used_as_ca(): void
    {
        $tls = new AgentTlsIdentity;
        $tls->ensure();
        config(['volumevault.agents.client.ca_certificate' => base64_encode(file_get_contents($tls->certificatePath()))]);
        $this->expectException(RuntimeException::class);
        $this->state();
    }

    public function test_tampered_persisted_ca_is_not_replaced(): void
    {
        $state = $this->state();
        $path = $state->caPath();
        $state->close();
        file_put_contents($path, 'tampered');
        $this->expectException(RuntimeException::class);
        try {
            $this->state();
        } finally {
            $this->assertSame('tampered', file_get_contents($path));
        }
    }

    public function test_successful_empty_docker_inventory_is_published(): void
    {
        $this->fakeServer();
        $collector = $this->mock(CollectAgentInventory::class);
        $collector->shouldReceive('available')->andReturnTrue();
        $collector->shouldReceive('handle')->andReturn(['volumes' => [], 'containers' => []]);
        $this->artisan('volumevault:agent --once')->assertSuccessful();
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/inventory') && $request['volumes'] === [] && $request['containers'] === []);
    }

    public function test_cli_boots_without_app_key_or_database(): void
    {
        $code = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();
        $app['config']->set('volumevault.agents.client.state_directory', $argv[1]);
        $app['config']->set('volumevault.agents.client.url', 'http://invalid.example');
        exit($kernel->handle(new Symfony\Component\Console\Input\ArrayInput([
            'command' => 'volumevault:agent', '--once' => true, '--no-interaction' => true,
        ]), new Symfony\Component\Console\Output\ConsoleOutput));
        PHP;
        $process = new Process([PHP_BINARY, '-r', $code, $this->directory.'/boot-state'], base_path(), [
            'APP_KEY' => '', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->directory.'/missing.sqlite',
            'VOLUMEVAULT_ORCHESTRATOR_URL' => 'http://invalid.example',
            'VOLUMEVAULT_AGENT_ENROLLMENT_TOKEN' => '',
        ]);
        $process->run();
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Unable to start agent:', $process->getOutput());
        $this->assertStringNotContainsString('SQLSTATE', $process->getOutput().$process->getErrorOutput());
        $this->assertStringNotContainsString('encryption key', $process->getOutput().$process->getErrorOutput());
    }
}
