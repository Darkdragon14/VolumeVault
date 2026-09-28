<?php

namespace Tests\Feature;

use App\Jobs\RunDestinationOperation;
use App\Models\AgentOperation;
use App\Models\BackupDestination;
use App\Models\DockerHost;
use App\Models\User;
use App\Services\Agents\AgentOperationBroker;
use App\Services\Agents\AgentOperationSpecification;
use App\Services\BackupDestinations\DestinationOperations;
use App\Services\BackupDestinations\DestinationStorage;
use App\Services\BackupDestinations\ExecuteDestinationOperation;
use App\Services\S3\S3ClientFactory;
use Aws\Result;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class DestinationOperationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['volumevault.mode' => 'orchestrator']);
    }

    private function host(): DockerHost
    {
        return DockerHost::factory()->create([
            'driver' => 'agent', 'agent_registered_at' => now(), 'agent_instance_id' => (string) Str::uuid(),
            'agent_protocol_version' => 1, 'agent_capabilities' => ['inventory-v1', 'destination-v1'],
            'agent_token_hash' => hash('sha256', 'test-token'), 'agent_active_operations' => 0,
        ]);
    }

    private function destination(array $attributes = []): BackupDestination
    {
        return BackupDestination::create([...[
            'name' => 'archives', 'provider' => 'aws_s3', 'region' => 'eu-central-1', 'bucket' => 'archives',
            'access_key_id' => 'archive', 'secret_access_key' => 'very-private-secret', 'is_active' => true,
        ], ...$attributes]);
    }

    private function receipt(array $data): array
    {
        return ['status' => 'success', 'data' => $data, 'logs' => 'very-private-secret', 'cleanup_complete' => true, 'finished_at' => now()->toIso8601String(), 'duration_seconds' => 1];
    }

    public function test_network_defaults_to_central_without_docker_and_explicit_agent_uses_broker(): void
    {
        $destination = $this->destination();
        $operations = app(DestinationOperations::class);
        $central = $operations->create($destination, 'test');
        $this->assertSame(1, $central->docker_host_id);
        Queue::assertPushed(RunDestinationOperation::class);
        $host = $this->host();
        $remote = $operations->create($destination, 'list', $host->id);
        $destination->update(['bucket' => 'changed', 'secret_access_key' => 'changed-secret']);
        $envelope = app(AgentOperationBroker::class)->pull($host);
        $this->assertSame($remote->id, $envelope['id']);
        $this->assertSame('archives', $envelope['spec']['destination']['bucket']);
        $this->assertSame('very-private-secret', $envelope['spec']['destination']['secret_access_key']);
        $this->assertSame($envelope, app(AgentOperationBroker::class)->pull($host));
        $this->assertStringNotContainsString('very-private-secret', DB::table('agent_operations')->where('id', $remote->id)->value('payload'));
        $result = $this->receipt(['objects' => [['key' => 'archive.tar.gz', 'display_name' => 'archive.tar.gz', 'size' => 10, 'last_modified' => null]], 'next_cursor' => 'opaque-provider-token']);
        app(AgentOperationBroker::class)->complete($host, $remote->id, $envelope['token'], $result);
        app(AgentOperationBroker::class)->complete($host, $remote->id, $envelope['token'], $result);
        $safe = $operations->safe($remote->fresh());
        $this->assertSame('archive.tar.gz', $safe['result']['data']['objects'][0]['key']);
        $this->assertSame('[redacted]', $safe['result']['logs']);
        $this->assertNull($remote->fresh()->payload);
        $this->assertStringNotContainsString('opaque-provider-token', json_encode($safe));
        $this->assertFalse($operations->verifies($destination, $host->id, $remote->id, 'archive.tar.gz'));
    }

    public function test_owner_is_automatic_and_cannot_be_overridden_or_sent_to_old_agent(): void
    {
        $host = $this->host();
        $destination = $this->destination(['provider' => 'local', 'docker_host_id' => $host->id, 'settings' => ['archive_path' => '/srv/archives']]);
        $operations = app(DestinationOperations::class);
        $this->assertSame($host->id, $operations->create($destination, 'stats')->docker_host_id);
        foreach ([1, $this->host()->id] as $id) {
            try {
                $operations->create($destination, 'test', $id);
                $this->fail('Owner override accepted');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('docker_host_id', $exception->errors());
            }
        }
        $host->forceFill(['agent_capabilities' => ['inventory-v1', 'backup-v1']])->save();
        $this->expectException(ValidationException::class);
        $operations->create($destination, 'test');
    }

    public function test_api_requires_admin_and_write_for_creation_read_for_polling_and_scopes_destination(): void
    {
        $destination = $this->destination();
        $path = '/api/v1/destinations/'.$destination->id.'/operations';
        Sanctum::actingAs(User::factory()->create(['role' => 'viewer']), ['read', 'write']);
        $this->postJson($path, ['action' => 'test'])->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']), ['read']);
        $this->postJson($path, ['action' => 'test'])->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']), ['write']);
        $id = $this->postJson($path, ['action' => 'test'])->assertAccepted()->json('data.id');
        $this->getJson($path.'/'.$id)->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']), ['read']);
        $response = $this->getJson($path.'/'.$id)->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertStringNotContainsString('very-private-secret', $response->getContent());
        $other = $this->destination();
        $this->getJson('/api/v1/destinations/'.$other->id.'/operations/'.$id)->assertNotFound();
    }

    public function test_result_rejects_non_integer_metrics(): void
    {
        $operations = app(DestinationOperations::class);
        $operation = $operations->create($this->destination(), 'stats', $this->host()->id);
        $this->expectException(ValidationException::class);
        $operations->complete($operation, $this->receipt(['used_bytes' => '10', 'object_count' => 1]));
    }

    public function test_listing_receipt_rejects_excess_objects_and_unknown_fields(): void
    {
        $operations = app(DestinationOperations::class);
        $operation = $operations->create($this->destination(), 'list', $this->host()->id, limit: 1);
        $object = ['key' => 'one.tar.gz', 'display_name' => 'one.tar.gz', 'size' => 1, 'last_modified' => null];
        foreach ([[$object, [...$object, 'key' => 'two.tar.gz']], [[...$object, 'credentials' => 'injected']]] as $objects) {
            try {
                $operations->complete($operation, $this->receipt(['objects' => $objects, 'next_cursor' => null]));
                $this->fail('Malformed page accepted');
            } catch (ValidationException) {
                $this->assertSame('pending', $operation->fresh()->status);
            }
        }
    }

    public function test_central_queue_worker_persists_sanitized_failure_and_never_reexecutes_completed_operation(): void
    {
        $operations = app(DestinationOperations::class);
        $operation = $operations->create($this->destination(), 'test');
        $this->mock(DestinationStorage::class)->shouldReceive('testReadOnly')->once()->andThrow(new \RuntimeException('credentials=very-private-secret'));
        $job = new RunDestinationOperation($operation->id);
        $job->handle($operations, app(ExecuteDestinationOperation::class));
        $job->handle($operations, app(ExecuteDestinationOperation::class));
        $safe = $operations->safe($operation->fresh());
        $this->assertSame('failed', $safe['result']['status']);
        $this->assertNull($safe['result']['data']);
        $this->assertStringNotContainsString('very-private-secret', json_encode($safe));
    }

    public function test_cursor_is_bound_to_locator_and_host_and_roundtrips_without_redaction(): void
    {
        $operations = app(DestinationOperations::class);
        $destination = $this->destination();
        $host = $this->host();
        $operation = $operations->create($destination, 'list', $host->id);
        $operations->complete($operation, $this->receipt(['objects' => [], 'next_cursor' => 'archive-token']));
        $cursor = $operations->safe($operation)['result']['data']['next_cursor'];
        $next = $operations->create($destination, 'list', $host->id, $cursor);
        $this->assertSame('archive-token', $next->payload['cursor']);
        $this->expectException(ValidationException::class);
        $operations->create($destination, 'list', $this->host()->id, $cursor);
    }

    public function test_remote_usage_waits_for_fresh_measurement_and_deduplicates_pending_refresh(): void
    {
        $host = $this->host();
        $destination = $this->destination(['provider' => 'local', 'docker_host_id' => $host->id, 'settings' => ['archive_path' => '/srv/archives']]);
        $storage = app(DestinationStorage::class);
        foreach ([1, 2] as $attempt) {
            try {
                $storage->storageUsage($destination);
                $this->fail('Expected pending measurement');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('pending', $exception->getMessage());
            }
        }
        $this->assertSame(1, AgentOperation::count());
        app(DestinationOperations::class)->complete(AgentOperation::first(), $this->receipt(['used_bytes' => 120, 'object_count' => 3]));
        $this->assertSame(['used_bytes' => 120, 'object_count' => 3], $storage->storageUsage($destination));
        $this->travel(31)->minutes();
        try {
            $storage->storageUsage($destination);
        } catch (\RuntimeException) {
            $this->assertSame(2, AgentOperation::count());
        }
    }

    public function test_real_execution_service_pages_s3_and_preserves_opaque_keys(): void
    {
        $destination = $this->destination();
        $operation = app(DestinationOperations::class)->create($destination, 'list', $this->host()->id, limit: 1);
        $client = Mockery::mock(S3Client::class);
        $client->shouldReceive('listObjectsV2')->once()->with(['Bucket' => 'archives', 'Prefix' => '', 'MaxKeys' => 1])->andReturn(new Result([
            'Contents' => [['Key' => 'archive.tar.gz', 'Size' => 5]], 'IsTruncated' => true, 'NextContinuationToken' => 'provider-page-2',
        ]));
        $this->mock(S3ClientFactory::class)->shouldReceive('make')->once()->andReturn($client);
        $result = app(ExecuteDestinationOperation::class)->handle($operation->payload);
        $this->assertSame('success', $result['status']);
        $this->assertSame('provider-page-2', $result['data']['next_cursor']);
        $this->assertSame('archive.tar.gz', $result['data']['objects'][0]['key']);
    }

    public function test_malformed_provider_data_becomes_a_deliverable_failure_instead_of_an_invalid_durable_receipt(): void
    {
        $operation = app(DestinationOperations::class)->create($this->destination(), 'list', $this->host()->id);
        $this->mock(DestinationStorage::class)->shouldReceive('listBackupObjectsPage')->once()->andReturn([
            'objects' => [['key' => 'archive.tar.gz', 'display_name' => 'archive.tar.gz', 'size' => -1, 'last_modified' => null]], 'next_cursor' => null,
        ]);
        $result = app(ExecuteDestinationOperation::class)->handle($operation->payload);
        $this->assertSame('failed', $result['status']);
        app(DestinationOperations::class)->complete($operation, $result);
        $this->assertSame('completed', $operation->fresh()->status);
    }

    public function test_structural_acceptance_does_not_resolve_private_endpoint_but_local_policy_rejects_it(): void
    {
        $operation = app(DestinationOperations::class)->create($this->destination(['provider' => 'custom_s3', 'endpoint' => 'http://127.0.0.1:9000']), 'test', $this->host()->id);
        $envelope = ['id' => $operation->id, 'token' => str_repeat('a', 64), 'kind' => 'destination', 'spec' => $operation->payload];
        app(AgentOperationSpecification::class)->validate($envelope);
        $this->expectException(\RuntimeException::class);
        app(AgentOperationSpecification::class)->validateLocalPolicy($envelope);
    }

    public function test_invalid_saved_configuration_returns_safe_validation_error_without_enqueueing(): void
    {
        $destination = $this->destination(['bucket' => '']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']), ['write']);
        $response = $this->postJson('/api/v1/destinations/'.$destination->id.'/operations', ['action' => 'test']);
        $response->assertUnprocessable()->assertJsonValidationErrors('destination');
        $this->assertStringNotContainsString('very-private-secret', $response->getContent());
        $this->assertDatabaseCount('agent_operations', 0);
    }
}
