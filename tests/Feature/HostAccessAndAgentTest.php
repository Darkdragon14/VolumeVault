<?php

namespace Tests\Feature;

use App\Models\AgentCommand;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerVolume;
use App\Models\Host;
use App\Models\User;
use App\Services\Hosts\HostEnrollmentTokens;
use App\Services\Hosts\HostAgentTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HostAccessAndAgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_selected_host_access_scopes_api_tokens_and_direct_reads(): void
    {
        $localHost = Host::localHost();
        $agentHost = Host::factory()->agent()->create(['name' => 'Remote Agent']);
        DockerVolume::create(['host_id' => $localHost->id, 'name' => 'local-data', 'exists' => true]);
        DockerVolume::create(['host_id' => $agentHost->id, 'name' => 'agent-data', 'exists' => true]);

        $destination = $this->destination();
        $agentJob = BackupJob::create([
            'host_id' => $agentHost->id,
            'name' => 'Agent job',
            'volume_name' => 'agent-data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);
        BackupRun::create([
            'host_id' => $localHost->id,
            'backup_job_id' => $agentJob->id,
            'status' => BackupRun::STATUS_SUCCESS,
            'trigger' => BackupRun::TRIGGER_MANUAL,
            'logs' => 'local host secret log',
        ]);

        $user = User::factory()->user()->create([
            'host_access_mode' => User::HOST_ACCESS_SELECTED,
        ]);
        $user->hosts()->sync([$agentHost->id]);
        $token = $user->createToken('read', ['read'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/volumes?all_hosts=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.host.id', $agentHost->id);

        $this->withToken($token)
            ->getJson('/api/v1/volumes')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withToken($token)
            ->getJson('/api/v1/volumes?host_id='.$localHost->id)
            ->assertForbidden();

        $this->withToken($token)
            ->getJson('/api/v1/backup-jobs/'.$agentJob->id)
            ->assertOk()
            ->assertJsonPath('data.host.id', $agentHost->id)
            ->assertJsonCount(0, 'data.runs')
            ->assertJsonMissing(['local host secret log']);
    }

    public function test_user_management_requires_selected_hosts_when_mode_is_selected(): void
    {
        $admin = User::factory()->admin()->create();
        $host = Host::factory()->agent()->create(['name' => 'Remote Agent']);

        $this->actingAs($admin)
            ->post('/users', [
                'name' => 'Operator',
                'email' => 'operator@example.com',
                'role' => User::ROLE_USER,
                'host_access_mode' => User::HOST_ACCESS_SELECTED,
                'host_ids' => [],
                'locale' => 'en',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertSessionHasErrors('host_ids');

        $this->actingAs($admin)
            ->post('/users', [
                'name' => 'Operator',
                'email' => 'operator@example.com',
                'role' => User::ROLE_USER,
                'host_access_mode' => User::HOST_ACCESS_SELECTED,
                'host_ids' => [$host->id],
                'locale' => 'en',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertRedirect(route('users.index'));

        $user = User::where('email', 'operator@example.com')->firstOrFail();

        $this->assertSame(User::HOST_ACCESS_SELECTED, $user->host_access_mode);
        $this->assertTrue($user->hosts()->whereKey($host)->exists());
    }

    public function test_free_active_host_limit_blocks_extra_agent_hosts(): void
    {
        $token = User::factory()->admin()->create()->createToken('write', ['read', 'write'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/hosts', ['name' => 'Agent One'])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'name'], 'enrollment_token']);

        $this->withToken($token)
            ->postJson('/api/v1/hosts', ['name' => 'Agent Two'])
            ->assertJsonValidationErrors('host');
    }

    public function test_agent_can_only_lease_and_complete_own_commands(): void
    {
        $host = Host::factory()->agent()->create(['name' => 'Agent One']);
        $otherHost = Host::factory()->agent()->create(['name' => 'Agent Two']);
        $token = app(HostEnrollmentTokens::class)->issue($host);
        $otherCommand = AgentCommand::factory()->create(['host_id' => $otherHost->id]);
        $command = AgentCommand::factory()->create([
            'host_id' => $host->id,
            'payload' => ['action' => 'sync'],
            'secret_payload' => ['destination' => ['password' => 'super-secret']],
        ]);

        $enrollment = $this->withToken($token)
            ->postJson('/api/v1/agent/enroll', [
                'agent_version' => '0.1.0',
                'docker_version' => '26.0.0',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $host->id)
            ->assertJsonStructure(['agent_token']);
        $firstAgentToken = $enrollment->json('agent_token');

        $agentToken = $this->withToken($token)
            ->postJson('/api/v1/agent/enroll')
            ->assertOk()
            ->json('agent_token');

        $this->withToken($firstAgentToken)
            ->postJson('/api/v1/agent/heartbeat')
            ->assertUnauthorized();

        $this->withToken($token)
            ->postJson('/api/v1/agent/heartbeat')
            ->assertUnauthorized();

        $this->withToken($agentToken)
            ->postJson('/api/v1/agent/heartbeat')
            ->assertOk();

        $this->withToken($token)
            ->postJson('/api/v1/agent/enroll')
            ->assertUnauthorized();

        $lease = $this->withToken($agentToken)
            ->postJson('/api/v1/agent/commands/lease')
            ->assertOk()
            ->assertJsonPath('data.id', $command->id)
            ->assertJsonPath('data.secret_payload.destination.password', 'super-secret')
            ->assertJsonStructure(['data' => ['lease_token']]);

        $this->withToken($agentToken)
            ->postJson('/api/v1/agent/commands/'.$otherCommand->id.'/complete', [
                'status' => AgentCommand::STATUS_COMPLETED,
                'lease_token' => $lease->json('data.lease_token'),
            ])
            ->assertForbidden();

        $rawSecretPayload = DB::table('agent_commands')->where('id', $command->id)->value('secret_payload');
        $this->assertIsString($rawSecretPayload);
        $this->assertStringNotContainsString('super-secret', $rawSecretPayload);
    }

    public function test_agent_sync_completion_updates_only_that_host_volumes(): void
    {
        $host = Host::factory()->agent()->create(['name' => 'Agent One']);
        $otherHost = Host::factory()->agent()->create(['name' => 'Agent Two']);
        DockerVolume::create(['host_id' => $host->id, 'name' => 'shared-data', 'exists' => true]);
        DockerVolume::create(['host_id' => $otherHost->id, 'name' => 'shared-data', 'exists' => true]);
        $token = app(HostEnrollmentTokens::class)->issue($host);
        $command = AgentCommand::factory()->create([
            'host_id' => $host->id,
            'type' => AgentCommand::TYPE_SYNC_VOLUMES,
            'status' => AgentCommand::STATUS_PENDING,
            'lease_until' => null,
        ]);

        $enrollment = $this->withToken($token)
            ->postJson('/api/v1/agent/enroll')
            ->assertOk();
        $agentToken = $enrollment->json('agent_token');

        $lease = $this->withToken($agentToken)
            ->postJson('/api/v1/agent/commands/lease')
            ->assertOk()
            ->assertJsonPath('data.id', $command->id);

        $completion = [
            'status' => AgentCommand::STATUS_COMPLETED,
            'lease_token' => $lease->json('data.lease_token'),
            'volumes' => [
                ['name' => 'agent-only-data', 'driver' => 'local'],
            ],
        ];

        $this->withToken($agentToken)
            ->postJson('/api/v1/agent/commands/'.$command->id.'/complete', [
                ...$completion,
            ])
            ->assertOk();

        $this->withToken($agentToken)
            ->postJson('/api/v1/agent/commands/'.$command->id.'/complete', $completion)
            ->assertStatus(409);

        $this->assertDatabaseHas('docker_volumes', ['host_id' => $host->id, 'name' => 'agent-only-data', 'exists' => true]);
        $this->assertDatabaseHas('docker_volumes', ['host_id' => $otherHost->id, 'name' => 'shared-data', 'exists' => true]);
    }

    public function test_successful_agent_sync_requires_a_volume_inventory(): void
    {
        $host = Host::factory()->agent()->create();
        $bootstrapToken = app(HostEnrollmentTokens::class)->issue($host);
        $agentToken = $this->withToken($bootstrapToken)
            ->postJson('/api/v1/agent/enroll')
            ->assertOk()
            ->json('agent_token');
        $command = AgentCommand::factory()->create([
            'host_id' => $host->id,
            'type' => AgentCommand::TYPE_SYNC_VOLUMES,
        ]);
        $leaseToken = $this->withToken($agentToken)
            ->postJson('/api/v1/agent/commands/lease')
            ->assertOk()
            ->json('data.lease_token');

        $this->withToken($agentToken)
            ->postJson('/api/v1/agent/commands/'.$command->id.'/complete', [
                'status' => AgentCommand::STATUS_COMPLETED,
                'lease_token' => $leaseToken,
            ])
            ->assertJsonValidationErrors('volumes');
    }

    public function test_agent_command_leases_default_to_sixty_minutes(): void
    {
        $this->freezeTime();

        $host = Host::factory()->agent()->create();
        $agentToken = app(HostAgentTokens::class)->issue($host);
        $command = AgentCommand::factory()->create([
            'host_id' => $host->id,
            'status' => AgentCommand::STATUS_PENDING,
        ]);

        $this->withToken($agentToken)
            ->postJson('/api/v1/agent/commands/lease')
            ->assertOk()
            ->assertJsonPath('data.id', $command->id);

        $this->assertTrue($command->refresh()->lease_until->equalTo(now()->addMinutes(60)));
    }

    public function test_expired_agent_heartbeats_are_marked_offline(): void
    {
        config(['volumevault.agent.offline_after_seconds' => 120]);

        $expiredHost = Host::factory()->agent()->create([
            'status' => Host::STATUS_ONLINE,
            'last_seen_at' => now()->subMinutes(3),
        ]);
        $neverSeenHost = Host::factory()->agent()->create([
            'status' => Host::STATUS_ONLINE,
            'last_seen_at' => null,
        ]);
        $freshHost = Host::factory()->agent()->create([
            'status' => Host::STATUS_ONLINE,
            'last_seen_at' => now()->subSeconds(30),
        ]);
        $inactiveHost = Host::factory()->agent()->create([
            'status' => Host::STATUS_ONLINE,
            'is_active' => false,
            'last_seen_at' => now()->subMinutes(3),
        ]);
        $leasedHost = Host::factory()->agent()->create([
            'status' => Host::STATUS_ONLINE,
            'last_seen_at' => now()->subMinutes(3),
        ]);
        AgentCommand::factory()->create([
            'host_id' => $leasedHost->id,
            'status' => AgentCommand::STATUS_LEASED,
            'lease_until' => now()->addMinutes(10),
        ]);

        $this->artisan('volumevault:agents:expire-offline')->assertExitCode(0);

        $this->assertSame(Host::STATUS_OFFLINE, $expiredHost->refresh()->status);
        $this->assertSame(Host::STATUS_OFFLINE, $neverSeenHost->refresh()->status);
        $this->assertSame(Host::STATUS_ONLINE, $freshHost->refresh()->status);
        $this->assertSame(Host::STATUS_ONLINE, $inactiveHost->refresh()->status);
        $this->assertSame(Host::STATUS_ONLINE, $leasedHost->refresh()->status);
    }

    public function test_agent_command_loop_does_not_retry_failed_lease_posts(): void
    {
        $credentialPath = storage_path('framework/testing/agent-token');

        if (file_exists($credentialPath)) {
            unlink($credentialPath);
        }

        config([
            'volumevault.agent.enabled' => true,
            'volumevault.agent.central_url' => 'https://volumevault.test',
            'volumevault.agent.token' => 'bootstrap-token',
            'volumevault.agent.credential_path' => $credentialPath,
        ]);

        $leaseRequests = 0;

        Http::fake(function (Request $request) use (&$leaseRequests): Response {
            if (str_ends_with($request->url(), '/enroll')) {
                return Http::response(['agent_token' => 'agent-token'], 200);
            }

            if (str_ends_with($request->url(), '/heartbeat')) {
                return Http::response(['data' => []], 200);
            }

            if (str_ends_with($request->url(), '/commands/lease')) {
                $leaseRequests++;

                return Http::response(['message' => 'temporary failure'], 500);
            }

            return Http::response([], 404);
        });

        try {
            Artisan::call('volumevault:agent', ['--once' => true]);
        } catch (RequestException) {
            // Expected: the failed non-idempotent lease request must not be retried.
        } finally {
            if (file_exists($credentialPath)) {
                unlink($credentialPath);
            }
        }

        $this->assertSame(1, $leaseRequests);
    }

    public function test_targeted_sync_rejects_inactive_agent_hosts(): void
    {
        $host = Host::factory()->agent()->create(['is_active' => false]);
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('write', ['read', 'write'])->plainTextToken;

        $this->actingAs($admin)
            ->post('/volumes/sync', ['host_id' => $host->id])
            ->assertSessionHas('error');

        $this->withToken($token)
            ->postJson('/api/v1/volumes/sync', ['host_id' => $host->id])
            ->assertStatus(422);

        $this->assertDatabaseMissing('agent_commands', [
            'host_id' => $host->id,
            'type' => AgentCommand::TYPE_SYNC_VOLUMES,
        ]);
    }

    public function test_agents_behind_the_same_ip_have_separate_operational_rate_limits(): void
    {
        $firstHost = Host::factory()->agent()->create();
        $secondHost = Host::factory()->agent()->create();
        $agentTokens = app(HostAgentTokens::class);
        $firstToken = $agentTokens->issue($firstHost);
        $secondToken = $agentTokens->issue($secondHost);

        foreach (range(1, 31) as $attempt) {
            $this->withToken($firstToken)
                ->postJson('/api/v1/agent/commands/lease')
                ->assertOk();
            $this->withToken($secondToken)
                ->postJson('/api/v1/agent/commands/lease')
                ->assertOk();
        }
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
}
