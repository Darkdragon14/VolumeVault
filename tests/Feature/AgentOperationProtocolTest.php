<?php

namespace Tests\Feature;

use App\Actions\Backup\AdvanceBackupGroupRun;
use App\Actions\Backup\CreateBackupGroupRun;
use App\Actions\Backup\CreateBackupRun;
use App\Actions\Backup\CreateBackupRunRecord;
use App\Actions\Backup\DeleteBackupJob;
use App\Actions\Backup\RunBackup;
use App\Actions\Runs\DispatchQueuedRun;
use App\Actions\Runs\ProcessRunFinalization;
use App\Jobs\RunBackupJob;
use App\Models\ActivityLog;
use App\Models\AgentOperation;
use App\Models\BackupDestination;
use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Models\DockerLabelBackupSetting;
use App\Models\DockerVolume;
use App\Models\NotificationChannel;
use App\Models\RestoreRun;
use App\Models\RunFinalization;
use App\Models\User;
use App\Services\Agents\AgentLifecycle;
use App\Services\Agents\AgentOperationEnvelope;
use App\Services\Agents\AgentOperationRedactor;
use App\Services\Agents\AgentOperationSpecification;
use App\Services\Agents\AgentRegistry;
use App\Services\Agents\AgentTlsIdentity;
use App\Services\Agents\DispatchAgentOperation;
use App\Services\Docker\DockerProcess;
use App\Services\Notifications\SendShoutrrrNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AgentOperationProtocolTest extends TestCase
{
    use RefreshDatabase;

    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['volumevault.mode' => 'orchestrator', 'volumevault.agents.enabled' => true, 'volumevault.agents.url' => 'https://orchestrator.test:8443']);
        $this->mock(AgentTlsIdentity::class)->shouldReceive('caCertificate')->andReturn('test-public-ca');
        $this->mock(DockerProcess::class)->shouldNotReceive('run');
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
        Queue::fake();
        Log::listen(function (MessageLogged $event): void {
            $this->logs[] = [$event->message, $event->context];
        });
        $this->withServerVariables(['HTTPS' => 'on']);
    }

    public function test_orchestrator_enqueue_is_idempotent_and_does_not_execute_central_docker(): void
    {
        [$host, $body, $token] = $this->registered();
        $run = $this->backup($host);
        foreach (range(1, 3) as $attempt) {
            $this->assertTrue(app(DispatchQueuedRun::class)->handle($run));
        }
        $this->assertDatabaseCount('agent_operations', 1);
        $this->assertNull(AgentOperation::firstOrFail()->payload);
        $this->assertSame('queued', $run->fresh()->status);
        $operation = $this->pull($body, $token);
        app(AgentOperationSpecification::class)->validate($operation);
        $this->assertSame('docker_volume', $operation['spec']['job']['source_type']);
        $this->assertSame('running', $run->fresh()->status);
        $this->assertSame(BackupJob::STATUS_RUNNING, $run->job->fresh()->status);
        Queue::assertNothingPushed();
        Process::assertNothingRan();
    }

    public function test_destination_protocol_encrypts_assignment_and_validates_receipt_before_idempotent_completion(): void
    {
        [$host, $body, $token] = $this->registered();
        $body['capabilities'][] = 'destination-v1';
        $host->forceFill(['agent_capabilities' => $body['capabilities']])->save();
        $destination = $this->backup($host)->job->destination;
        $operations = app(\App\Services\BackupDestinations\DestinationOperations::class);
        $requested = $operations->create($destination, 'stats', $host->id);
        $operation = $this->pull($body, $token);
        $this->assertSame('destination', $operation['kind']);
        $this->assertSame($requested->id, $operation['id']);
        $this->assertSame($operation, $this->pull($body, $token));
        $receipt = ['status' => 'success', 'logs' => 'broker-private-secret', 'data' => ['used_bytes' => 42, 'object_count' => 1], 'cleanup_complete' => true, 'duration_seconds' => 1, 'finished_at' => now()->toIso8601String()];
        $payload = [...$body, 'token' => $operation['token'], 'result' => $receipt];
        $url = 'operations/'.$operation['id'].'/complete';
        $this->sendAgent($url, $token, [...$payload, 'result' => [...$receipt, 'data' => ['used_bytes' => -1, 'object_count' => 1]]])->assertUnprocessable();
        $this->assertSame('running', $requested->fresh()->status);
        $this->sendAgent($url, $token, $payload)->assertOk();
        $this->sendAgent($url, $token, $payload)->assertOk();
        $this->assertSame('[redacted]', $requested->fresh()->result['logs']);
        $this->assertSame(42, $requested->fresh()->result['data']['used_bytes']);
    }

    public function test_destination_listing_transport_preserves_whitespace_and_secret_substrings_in_resource_identity(): void
    {
        [$host, $body, $token] = $this->registered();
        $body['capabilities'][] = 'destination-v1';
        $host->forceFill(['agent_capabilities' => $body['capabilities']])->save();
        $destination = $this->backup($host)->job->destination;
        $requested = app(\App\Services\BackupDestinations\DestinationOperations::class)->create($destination, 'list', $host->id);
        $operation = $this->pull($body, $token);
        $key = ' broker-access-secret/archive.tar.gz';
        $receipt = ['status' => 'success', 'logs' => 'broker-private-secret', 'data' => ['objects' => [['key' => $key, 'display_name' => $key, 'size' => 42, 'last_modified' => null]], 'next_cursor' => ' opaque-token '], 'cleanup_complete' => true, 'duration_seconds' => 1, 'finished_at' => now()->toIso8601String()];
        $this->sendAgent('operations/'.$operation['id'].'/complete', $token, [...$body, 'token' => $operation['token'], 'result' => $receipt])->assertOk();
        $this->assertSame($key, $requested->fresh()->result['data']['objects'][0]['key']);
        $this->assertSame($key, $requested->fresh()->result['data']['objects'][0]['display_name']);
        $this->assertSame(' opaque-token ', $requested->fresh()->result['data']['next_cursor']);
        $this->assertSame('[redacted]', $requested->fresh()->result['logs']);
    }

    public function test_inventory_created_label_job_executes_and_completion_applies_deferred_settings(): void
    {
        [$host, $body, $token] = $this->registered();
        $seed = $this->backup($host);
        $destinationId = $seed->job->backup_destination_id;
        $seed->job->delete();
        $host->refresh()->forceFill(['agent_capabilities' => [...$body['capabilities'], 'docker-labels-v1']])->save();
        DockerLabelBackupSetting::current($host->id)->update(['enabled' => true, 'backup_destination_id' => $destinationId]);
        $inventory = [
            ...$body, 'sequence' => 1, 'volumes' => [['name' => 'app_data']], 'host_path_allowlist' => [],
            'containers' => [['id' => str_repeat('a', 64), 'names' => 'app']],
            'label_inventory' => ['complete' => true, 'containers' => [[
                'id' => str_repeat('a', 64), 'name' => 'app', 'running' => true, 'created' => now()->toIso8601String(),
                'labels' => ['dev.darkdragon14.volumevault.enable' => 'true', 'dev.darkdragon14.volumevault.backup.volume' => 'app_data'],
                'mounts' => [['name' => 'app_data', 'destination' => '/data']],
            ]]],
        ];
        $this->sendAgent('inventory', $token, $inventory)->assertOk();
        $job = BackupJob::firstOrFail();
        $this->assertTrue($job->isDockerLabelManaged());
        $run = app(CreateBackupRun::class)->handle($job, BackupRun::TRIGGER_MANUAL);
        $this->enqueue($run);
        $operation = $this->pull($body, $token);
        $this->assertSame('app_data', $operation['spec']['job']['volume_name']);
        $inventory['sequence'] = 2;
        $inventory['label_inventory']['containers'][0]['labels']['dev.darkdragon14.volumevault.backup.retention-days'] = '12';
        $this->sendAgent('inventory', $token, $inventory)->assertOk();
        $this->assertSame('apply', $job->refresh()->pending_label_reconciliation['action']);
        $this->assertNull($job->retention_days);
        $this->complete($body, $token, $operation, ['cleanup_complete' => false])->assertUnprocessable();
        $this->assertNull($job->refresh()->retention_days);
        $this->complete($body, $token, $operation)->assertOk();
        $this->assertSame(12, $job->refresh()->retention_days);
        $this->assertNull($job->pending_label_reconciliation);
        $this->assertSame('success', $run->refresh()->status);

        $next = app(CreateBackupRun::class)->handle($job, BackupRun::TRIGGER_MANUAL);
        $pending = $this->enqueue($next);
        $inventory['sequence'] = 3;
        $inventory['label_inventory']['containers'][0]['labels'] = [];
        $this->sendAgent('inventory', $token, $inventory)->assertOk();
        $this->assertSame('cancelled', $pending->refresh()->status);
        $this->assertSame('cancelled', $next->refresh()->status);
        $this->assertSame(BackupJob::STATUS_ERROR, $job->refresh()->status);
        Process::assertNothingRan();
    }

    public function test_lost_pull_response_redelivers_identical_receipt_and_ciphertext_after_days(): void
    {
        [$host, $body, $token] = $this->registered();
        $first = $this->enqueue($this->backup($host));
        $second = $this->enqueue($this->backup($host));
        $operation = $this->pull($body, $token);
        $raw = DB::table('agent_operations')->where('id', $first->id)->first();
        $this->travel(10)->days();
        $this->assertSame($operation, $this->pull($body, $token));
        $after = DB::table('agent_operations')->where('id', $first->id)->first();
        $this->assertSame($raw->payload, $after->payload);
        $this->assertSame($raw->delivery_token, $after->delivery_token);
        $this->assertSame('pending', $second->fresh()->status);
        $this->assertSame(1, AgentOperation::where('status', 'running')->count());
    }

    public function test_maintenance_blocks_pending_delivery_but_allows_existing_receipt_and_completion(): void
    {
        [$host, $body, $token] = $this->registered();
        $run = $this->backup($host);
        $pending = $this->enqueue($run);
        $host->forceFill(['maintenance_requested_at' => now()])->save();
        $this->sendAgent('operations/pull', $token, $body)->assertOk()->assertJsonPath('operation', null);
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertSame('queued', $run->fresh()->status);
        $host->forceFill(['maintenance_requested_at' => null])->save();
        $operation = $this->pull($body, $token);
        $host->forceFill(['maintenance_requested_at' => now()])->save();
        $this->assertSame($operation, $this->pull($body, $token));
        $this->complete($body, $token, $operation)->assertOk();
        $this->assertSame('success', $run->fresh()->status);
    }

    public function test_operation_callbacks_require_exact_host_and_delivery_token(): void
    {
        [$host, $body, $token] = $this->registered();
        [$other, $otherBody, $otherToken] = $this->registered();
        $run = $this->backup($host);
        $this->enqueue($run);
        $operation = $this->pull($body, $token);
        $this->sendAgent('operations/pull', $otherToken, $otherBody)->assertOk()->assertJsonPath('operation', null);
        foreach (['progress', 'complete'] as $endpoint) {
            $path = 'operations/'.$operation['id'].'/'.$endpoint;
            $data = ['token' => $operation['token'], 'result' => $this->operationResult()];
            $this->sendAgent($path, $otherToken, [...$otherBody, ...$data])->assertNotFound();
            $this->sendAgent($path, $token, [...$body, ...$data, 'token' => str_repeat('0', 64)])->assertNotFound();
            $this->sendAgent($path, $other->uuid.'.'.$body['credential'], [...$body, ...$data])->assertUnauthorized();
        }
        $user = User::factory()->create(['role' => 'admin']);
        $this->sendAgent('operations/pull', $user->createToken('user', ['*'])->plainTextToken, $body)->assertUnauthorized();
        $this->actingAs($user);
        $this->sendAgent('operations/pull', '', $body)->assertUnauthorized();
        $this->assertSame('running', $run->fresh()->status);
        $this->assertDatabaseCount('run_finalizations', 0);
    }

    public function test_reenrollment_cannot_repull_old_assignment_but_can_complete_known_receipt(): void
    {
        [$host, $body, $token] = $this->registered();
        $run = $this->backup($host);
        $pending = $this->enqueue($run);
        $operation = $this->pull($body, $token);
        [$sameHost, $enrollment, $newBody] = $this->pending($host);
        $this->sendAgent('enroll', $enrollment, $newBody)->assertOk();
        $newToken = $sameHost->uuid.'.'.$newBody['credential'];
        $this->sendAgent('operations/pull', $token, $body)->assertUnauthorized();
        $this->sendAgent('operations/pull', $newToken, $newBody)->assertOk()->assertJsonPath('operation', null);
        $this->assertSame($body['instance_id'], $pending->fresh()->owner_instance_id);
        $this->complete($newBody, $newToken, $operation)->assertOk();
        $this->assertSame('success', $run->fresh()->status);
    }

    public function test_completion_persists_history_once_without_overwrite_or_duplicate_finalization(): void
    {
        [$host, $body, $token] = $this->registered();
        $run = $this->backup($host);
        $run->job->update(['notifications_enabled' => true]);
        $channel = NotificationChannel::create(['name' => 'Test', 'service' => NotificationChannel::SERVICE_ADVANCED, 'notification_level' => NotificationChannel::LEVEL_INFO, 'is_active' => true, 'url' => 'ntfy://notify.test/test']);
        $run->job->notificationChannels()->attach($channel);
        $this->enqueue($run);
        $operation = $this->pull($body, $token);
        $this->complete($body, $token, $operation)->assertOk();
        $this->assertSame('archives/backup.tar.gz', $run->refresh()->backup_key);
        $this->assertSame(1234, $run->backup_size_bytes);
        $this->assertSame('success', $run->status);
        $this->assertSame(BackupJob::STATUS_ACTIVE, $run->job->fresh()->status);
        $this->assertNotNull($run->job->fresh()->last_success_at);
        $this->assertDatabaseCount('run_finalizations', 2);
        $history = $run->getAttributes();
        $finalizations = RunFinalization::all()->toArray();
        $this->travel(2)->minutes();
        $this->complete($body, $token, $operation, ['status' => 'failed', 'error_message' => 'late conflicting callback', 'backup_key' => 'wrong', 'backup_size_bytes' => 0])->assertOk();
        $this->assertSame($history, $run->fresh()->getAttributes());
        $this->assertSame($finalizations, RunFinalization::all()->toArray());
        $this->assertSame(1, ActivityLog::where('event_type', 'agent_operation_finished')->count());
        $this->assertNull(AgentOperation::findOrFail($operation['id'])->payload);
    }

    public function test_result_requires_strict_cleanup_complete_before_any_finalization(): void
    {
        [$host, $body, $token] = $this->registered();
        $run = $this->backup($host);
        $pending = $this->enqueue($run);
        $operation = $this->pull($body, $token);
        foreach ([false, 1, 'true', null] as $value) {
            $this->complete($body, $token, $operation, ['cleanup_complete' => $value])->assertUnprocessable()->assertJsonValidationErrors('result.cleanup_complete');
            $this->assertSame('running', $run->fresh()->status);
            $this->assertSame('running', $pending->fresh()->status);
            $this->assertNull($run->fresh()->finished_at);
            $this->assertDatabaseCount('run_finalizations', 0);
        }
        $this->complete($body, $token, $operation)->assertOk();
    }

    public function test_payload_and_token_are_encrypted_and_hidden_and_callback_logs_are_redacted(): void
    {
        [$host, $body, $token] = $this->registered();
        $run = $this->backup($host);
        $pending = $this->enqueue($run);
        $operation = $this->pull($body, $token);
        $pending->refresh();
        foreach (['payload', 'delivery_token', 'owner_instance_id'] as $field) {
            $this->assertArrayNotHasKey($field, $pending->toArray());
        }
        $raw = (array) DB::table('agent_operations')->where('id', $pending->id)->first();
        $this->assertSame($operation['spec'], $pending->payload);
        foreach (['broker-access-secret', 'broker-private-secret', $operation['token']] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($raw));
            $this->assertStringNotContainsString($secret, $pending->toJson());
        }
        $message = 'failure broker-access-secret broker-private-secret '.$operation['token'];
        $this->complete($body, $token, $operation, ['status' => 'failed', 'logs' => $message, 'error_message' => $message])->assertOk();
        $this->assertSame(BackupJob::STATUS_ERROR, $run->job->fresh()->status);
        $this->assertStringContainsString('failure', $run->fresh()->logs);
        foreach (['broker-access-secret', 'broker-private-secret', $operation['token']] as $secret) {
            foreach ([$run->fresh()->toJson(), $run->job->fresh()->toJson(), ActivityLog::all()->toJson(), json_encode($this->logs)] as $serialized) {
                $this->assertStringNotContainsString($secret, $serialized);
            }
        }
    }

    public function test_delivery_envelope_requires_the_assigned_agent_credential_and_detects_tampering(): void
    {
        [$host, $body, $token] = $this->registered();
        [, , $otherToken] = $this->registered();
        $this->enqueue($this->backup($host));
        $envelope = $this->sendAgent('operations/pull', $token, $body)->assertOk()->json('operation');
        $cipher = app(AgentOperationEnvelope::class);
        $this->assertSame('backup', $cipher->open($envelope, $token)['kind']);
        foreach ([[$envelope, $otherToken], [[...$envelope, 'ciphertext' => $envelope['ciphertext'].'tampered'], $token]] as [$invalid, $credential]) {
            try {
                $cipher->open($invalid, $credential);
                $this->fail('Invalid envelopes must not reach execution.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Agent operation envelope could not be authenticated.', $exception->getMessage());
            }
        }
    }

    public function test_completed_receipt_survives_job_deletion_until_agent_acknowledges_it(): void
    {
        [$host, $body, $token] = $this->registered();
        $run = $this->backup($host);
        $run->job->update(['notifications_enabled' => false]);
        $this->enqueue($run);
        $operation = $this->pull($body, $token);
        $this->complete($body, $token, $operation)->assertOk();
        app(DeleteBackupJob::class)->handle($run->job);
        $this->assertModelMissing($run);
        $receipt = AgentOperation::findOrFail($operation['id']);
        $this->assertNull($receipt->backup_run_id);
        $this->assertSame('completed', $receipt->status);
        $this->sendAgent('operations/'.$operation['id'].'/progress', $token, [...$body, 'token' => $operation['token']])->assertOk();
        $this->complete($body, $token, $operation)->assertOk()->assertJsonPath('acknowledged', true);
    }

    public function test_missing_capability_rejects_pull_without_claiming_pending_run(): void
    {
        [$host, $body, $token] = $this->registered();
        $run = $this->backup($host);
        $pending = $this->enqueue($run);
        $host->forceFill(['agent_capabilities' => ['inventory-v1', 'restore-v1']])->save();
        $this->sendAgent('operations/pull', $token, $body)->assertUnprocessable()->assertJsonValidationErrors('docker_host_id');
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertNull($pending->fresh()->payload);
        $this->assertSame('queued', $run->fresh()->status);
    }

    public function test_inactive_destination_fails_without_assigning_or_stranding_the_operation(): void
    {
        [$host, $body, $token] = $this->registered();
        $run = $this->backup($host);
        $pending = $this->enqueue($run);
        $run->job->destination->update(['is_active' => false]);
        $this->sendAgent('operations/pull', $token, $body)->assertOk()->assertJsonPath('operation', null);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertNull($run->fresh()->started_at);
        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->assertNull($pending->fresh()->delivery_token);
        $this->assertSame(BackupJob::STATUS_ERROR, $run->job->fresh()->status);
    }

    public function test_restore_callback_target_mismatch_rolls_back_and_success_updates_only_exact_host(): void
    {
        [$source] = $this->registered();
        [$target, $body, $token] = $this->registered();
        $backup = $this->backup($source);
        $restore = RestoreRun::create([
            'backup_job_id' => $backup->backup_job_id, 'backup_destination_id' => $backup->backup_destination_id_snapshot,
            'source_docker_host_id' => $source->id, 'target_docker_host_id' => $target->id,
            'selected_backup_key' => 'archives/backup.tar.gz', 'source_volume_name' => 'app_data',
            'target_volume_name' => 'restored', 'mode' => RestoreRun::MODE_NEW_VOLUME, 'status' => 'queued', 'backup_before_overwrite' => false,
        ]);
        foreach ([$source->id, DockerHost::LOCAL_ID] as $hostId) {
            DockerVolume::create(['docker_host_id' => $hostId, 'name' => 'restored', 'exists' => false]);
        }
        $pending = $this->enqueue($restore);
        $operation = $this->pull($body, $token);
        app(AgentOperationSpecification::class)->validate($operation);
        $before = $restore->fresh()->getAttributes();
        $this->complete($body, $token, $operation, ['target_volume_name' => 'wrong-target'])->assertUnprocessable();
        $this->assertSame($before, $restore->fresh()->getAttributes());
        $this->assertSame('running', $pending->fresh()->status);
        $this->assertDatabaseMissing('docker_volumes', ['docker_host_id' => $target->id, 'name' => 'restored']);
        $this->assertDatabaseCount('run_finalizations', 0);
        $this->complete($body, $token, $operation, ['target_volume_name' => 'restored'])->assertOk();
        $this->assertSame('success', $restore->fresh()->status);
        $this->assertDatabaseHas('docker_volumes', ['docker_host_id' => $target->id, 'name' => 'restored', 'exists' => true]);
        foreach ([$source->id, DockerHost::LOCAL_ID] as $hostId) {
            $this->assertDatabaseHas('docker_volumes', ['docker_host_id' => $hostId, 'name' => 'restored', 'exists' => false]);
        }
    }

    public function test_busy_agent_report_blocks_next_assignment_after_completion_until_idle_heartbeat(): void
    {
        [$host, $body, $token] = $this->registered();
        $this->enqueue($this->backup($host));
        $nextRun = $this->backup($host);
        $next = $this->enqueue($nextRun);
        $operation = $this->pull($body, $token);
        $heartbeat = [...$body, 'docker_status' => 'ready', 'active_operations' => 1];
        $this->sendAgent('heartbeat', $token, $heartbeat)->assertOk();
        $this->assertSame(1, $host->fresh()->agent_active_operations);
        $this->assertSame($operation, $this->pull($body, $token));
        $this->complete($body, $token, $operation)->assertOk();
        $this->sendAgent('operations/pull', $token, $body)->assertOk()->assertJsonPath('operation', null);
        $this->assertSame('pending', $next->fresh()->status);
        $this->assertSame('queued', $nextRun->fresh()->status);
        $this->sendAgent('heartbeat', $token, [...$heartbeat, 'active_operations' => 0])->assertOk();
        $this->assertSame($next->id, $this->pull($body, $token)['id']);
    }

    public function test_paused_job_does_not_falsely_claim_an_operation_and_can_resume(): void
    {
        [$host, $body, $token] = $this->registered();
        $run = $this->backup($host);
        $pending = $this->enqueue($run);
        $run->job->update(['status' => BackupJob::STATUS_PAUSED]);
        $this->sendAgent('operations/pull', $token, $body)->assertOk()->assertJsonPath('operation', null);
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertNull($pending->fresh()->payload);
        $this->assertNull($pending->fresh()->delivery_token);
        $this->assertNull($run->fresh()->started_at);
        $run->job->update(['status' => BackupJob::STATUS_ACTIVE]);
        $this->assertSame($pending->id, $this->pull($body, $token)['id']);
    }

    public function test_progress_updates_only_assigned_run_and_does_not_finalize_it(): void
    {
        [$host, $body, $token] = $this->registered();
        $run = $this->backup($host);
        $pending = $this->enqueue($run);
        $operation = $this->pull($body, $token);
        $before = $pending->fresh()->last_progress_at;
        $this->travel(5)->minutes();
        $this->sendAgent('operations/'.$operation['id'].'/progress', $token, [...$body, 'token' => $operation['token']])->assertOk()->assertJsonPath('acknowledged', true);
        $this->assertTrue($pending->fresh()->last_progress_at->greaterThan($before));
        $this->assertTrue($run->fresh()->last_heartbeat_at->equalTo($pending->fresh()->last_progress_at));
        $this->assertSame('running', $run->fresh()->status);
        $this->assertNull($run->fresh()->finished_at);
        $this->assertDatabaseCount('run_finalizations', 0);
    }

    public static function safetyTargets(): array
    {
        return [['app_data'], ['broker-access-secret-data']];
    }

    #[DataProvider('safetyTargets')]
    public function test_restore_of_paused_job_records_safety_archive_once_before_runtime_receipt_is_discarded(string $target): void
    {
        [$host, $body, $token] = $this->registered();
        $backup = $this->backup($host);
        $backup->job->update(['status' => BackupJob::STATUS_PAUSED]);
        $restore = RestoreRun::create([
            'backup_job_id' => $backup->backup_job_id, 'backup_destination_id' => $backup->backup_destination_id_snapshot,
            'source_docker_host_id' => $host->id, 'target_docker_host_id' => $host->id,
            'selected_backup_key' => 'archives/old.tar.gz', 'source_volume_name' => $target,
            'target_volume_name' => $target, 'mode' => 'safe_inplace', 'confirmation_text' => $target,
            'status' => 'queued', 'backup_before_overwrite' => true,
        ]);
        $this->enqueue($restore);
        $operation = $this->pull($body, $token);
        $this->complete($body, $token, $operation)->assertUnprocessable();
        $this->complete($body, $token, $operation, ['safety_backup' => []])->assertUnprocessable();
        $safety = [
            'status' => 'success', 'backup_filename' => 'agent-safety.tar.gz',
            'backup_key' => 'archives/agent-safety.tar.gz', 'backup_size_bytes' => 99,
            'source_volume_name' => (new AgentOperationRedactor($operation))->clean($target), 'duration_seconds' => 2, 'error_message' => null,
        ];
        $this->complete($body, $token, $operation, ['safety_backup' => $safety])->assertOk();
        $this->complete($body, $token, $operation, ['safety_backup' => $safety])->assertOk();
        $record = $restore->refresh()->preRestoreBackup;
        $this->assertNotNull($record);
        $this->assertSame($host->id, $record->docker_host_id);
        $this->assertSame($target, $record->source_volume_name);
        $this->assertSame('archives/agent-safety.tar.gz', $record->backup_key);
        $this->assertSame($backup->job->backup_destination_id, $record->backup_destination_id_snapshot);
        $this->assertSame(1, BackupRun::where('trigger', BackupRun::TRIGGER_PRE_RESTORE)->count());
        $this->assertSame(BackupJob::STATUS_PAUSED, $backup->job->fresh()->status);
        $next = $this->enqueue($this->backup($host));
        $this->assertSame($next->id, $this->pull($body, $token)['id']);
    }

    public function test_durable_group_advances_two_hosts_once_after_receipts_and_survives_lost_ack(): void
    {
        [$first, $body, $token] = $this->registered();
        [$second, $secondBody, $secondToken] = $this->registered();
        $run = $this->groupRun([$first, $second]);
        $children = $run->memberRuns()->orderBy('id')->get();
        $this->assertSame($children->pluck('id')->all(), $run->member_run_ids);
        $this->assertFalse(app(DispatchAgentOperation::class)->handle($children[1]));
        $this->assertFalse(app(DispatchQueuedRun::class)->handle($children[0]));
        $this->artisan('volumevault:dispatch-queued-runs')->assertSuccessful();
        $operation = $this->pull($body, $token);
        $this->assertSame($operation, $this->pull($body, $token));
        $this->sendAgent('operations/pull', $secondToken, $secondBody)->assertOk()->assertJsonPath('operation', null);
        $this->artisan('volumevault:dispatch-queued-runs')->assertSuccessful();
        $this->assertDatabaseCount('agent_operations', 1);
        $this->complete($body, $token, $operation, ['cleanup_complete' => false])->assertUnprocessable();
        $this->artisan('volumevault:dispatch-queued-runs')->assertSuccessful();
        $this->assertSame($children[0]->id, $run->fresh()->current_member_run_id);
        $this->complete($body, $token, $operation)->assertOk();
        $this->complete($body, $token, $operation)->assertOk();
        $this->artisan('volumevault:dispatch-queued-runs')->assertSuccessful();
        $secondOperation = $this->pull($secondBody, $secondToken);
        $this->assertNotSame($operation['id'], $secondOperation['id']);
        $this->complete($secondBody, $secondToken, $secondOperation)->assertOk();
        foreach (range(1, 3) as $attempt) {
            $this->artisan('volumevault:dispatch-queued-runs')->assertSuccessful();
        }
        $this->assertSame('success', $run->fresh()->status);
        $this->assertSame(2, $run->fresh()->succeeded_members);
        $this->assertDatabaseCount('agent_operations', 2);
        $this->assertSame(1, RunFinalization::where('backup_group_run_id', $run->id)->where('type', RunFinalization::TYPE_FINISHED_NOTIFICATION)->count());
        $this->assertSame(1, RunFinalization::where('backup_group_run_id', $run->id)->where('type', RunFinalization::TYPE_STARTED_NOTIFICATION)->count());
        $this->assertSame(0, RunFinalization::whereNotNull('backup_run_id')->count());
        app(SendShoutrrrNotification::class)->shouldReceive('sendGroupRunFinishedToChannel')->once();
        $finalization = RunFinalization::where('backup_group_run_id', $run->id)->where('type', RunFinalization::TYPE_FINISHED_NOTIFICATION)->firstOrFail();
        app(ProcessRunFinalization::class)->handle($finalization->id);
        app(ProcessRunFinalization::class)->handle($finalization->id);
        $this->assertSame(RunFinalization::STATUS_COMPLETED, $finalization->fresh()->status);
    }

    public function test_group_freezes_sources_destinations_order_and_failure_policy_before_first_dispatch(): void
    {
        [$host, $body, $token] = $this->registered();
        $run = $this->groupRun([$host, $host], BackupJobGroup::FAILURE_POLICY_STOP);
        $children = $run->memberRuns()->orderBy('id')->get();
        $originalDestination = $children[0]->destinationForRun()->bucket;
        $other = BackupDestination::create(['name' => 'Other', 'provider' => 'aws_s3', 'bucket' => 'other-bucket', 'access_key_id' => 'other', 'secret_access_key' => 'other', 'is_active' => true]);
        $children[0]->job->update(['volume_name' => 'mutated', 'backup_destination_id' => $other->id, 'stop_containers_before_backup' => true]);
        $run->group->update(['failure_policy' => BackupJobGroup::FAILURE_POLICY_CONTINUE]);
        $this->artisan('volumevault:dispatch-queued-runs')->assertSuccessful();
        $operation = $this->pull($body, $token);
        $this->assertSame('app_data', $operation['spec']['job']['volume_name']);
        $this->assertSame($originalDestination, $operation['spec']['destination']['bucket']);
        $this->assertFalse($operation['spec']['job']['stop_containers_before_backup']);
        $this->complete($body, $token, $operation, ['status' => 'failed', 'error_message' => 'failed member'])->assertOk();
        $this->artisan('volumevault:dispatch-queued-runs')->assertSuccessful();
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(1, $run->fresh()->failed_members);
        $this->assertSame('cancelled', $children[1]->fresh()->status);
        $this->assertDatabaseCount('agent_operations', 1);
        $this->assertFalse(app(DispatchAgentOperation::class)->handle($children[1]));
    }

    public function test_group_continue_policy_and_terminal_cleanup_block_advancement_and_finish(): void
    {
        [$host, $body, $token] = $this->registered();
        $run = $this->groupRun([$host, $host]);
        $children = $run->memberRuns()->orderBy('id')->get();
        app(AdvanceBackupGroupRun::class)->handle($run);
        $operation = $this->pull($body, $token);
        $this->complete($body, $token, $operation, ['status' => 'failed'])->assertOk();
        $children[0]->update(['docker_container_cleanup_pending' => true]);
        app(AdvanceBackupGroupRun::class)->handle($run);
        $this->assertDatabaseCount('agent_operations', 1);
        $this->assertSame('running', $run->fresh()->status);
        $children[0]->update(['docker_container_cleanup_pending' => false]);
        app(AdvanceBackupGroupRun::class)->handle($run);
        $operation = $this->pull($body, $token);
        $this->complete($body, $token, $operation)->assertOk();
        $children[1]->update(['stopped_container_ids' => ['container-needs-restart']]);
        app(AdvanceBackupGroupRun::class)->handle($run);
        $this->assertSame('running', $run->fresh()->status);
        $this->assertSame(0, RunFinalization::where('type', RunFinalization::TYPE_FINISHED_NOTIFICATION)->count());
        $children[1]->update(['stopped_container_ids' => null]);
        app(AdvanceBackupGroupRun::class)->handle($run);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(1, $run->fresh()->failed_members);
        $this->assertSame(1, $run->fresh()->succeeded_members);
    }

    public function test_group_waits_for_maintenance_without_holding_host_drain_open_or_replaying_work(): void
    {
        [$first, $body, $token] = $this->registered();
        [$second, $secondBody, $secondToken] = $this->registered();
        $run = $this->groupRun([$first, $second]);
        $first->forceFill(['maintenance_requested_at' => now()])->save();
        app(AdvanceBackupGroupRun::class)->handle($run);
        $this->assertSame('queued', $run->fresh()->status);
        $this->assertDatabaseCount('agent_operations', 0);
        $first->forceFill(['maintenance_requested_at' => null])->save();
        app(AdvanceBackupGroupRun::class)->handle($run);
        $operation = $this->pull($body, $token);
        $second->forceFill(['maintenance_requested_at' => now()])->save();
        $this->complete($body, $token, $operation)->assertOk();
        $this->artisan('volumevault:dispatch-queued-runs')->assertSuccessful();
        $this->assertDatabaseCount('agent_operations', 1);
        $this->assertSame(0, app(AgentLifecycle::class)->activeOperations($second->fresh()));
        $second->forceFill(['maintenance_requested_at' => null])->save();
        $this->artisan('volumevault:dispatch-queued-runs')->assertSuccessful();
        $operation = $this->pull($secondBody, $secondToken);
        $this->complete($secondBody, $secondToken, $operation)->assertOk();
        $this->artisan('volumevault:dispatch-queued-runs')->assertSuccessful();
        $this->assertSame('success', $run->fresh()->status);
    }

    public function test_mixed_group_selects_local_queue_member_and_does_not_reconcile_future_members(): void
    {
        config(['volumevault.mode' => 'hybrid', 'queue.default' => 'database']);
        [$remote, $body, $token] = $this->registered();
        $local = DockerHost::findOrFail(DockerHost::LOCAL_ID);
        $run = $this->groupRun([$remote, $local]);
        $children = $run->memberRuns()->orderBy('id')->get();
        $this->travel(2)->hours();
        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();
        $this->assertSame('queued', $children[1]->fresh()->status);
        $remote->forceFill(['last_seen_at' => now()])->save();
        app(AdvanceBackupGroupRun::class)->handle($run);
        Queue::assertNotPushed(RunBackupJob::class);
        $operation = $this->pull($body, $token);
        $this->complete($body, $token, $operation)->assertOk();
        app(AdvanceBackupGroupRun::class)->handle($run);
        Queue::assertPushed(RunBackupJob::class, fn ($job) => $job->backupRunId === $children[1]->id);
        $backup = $this->mock(RunBackup::class);
        $backup->shouldReceive('handle')->once()->withArgs(fn (BackupRun $child) => $child->id === $children[1]->id)
            ->andReturnUsing(fn (BackupRun $child) => $child->update(['status' => 'success', 'finished_at' => now()]));
        (new RunBackupJob($children[1]->id))->handle($backup);
        app(AdvanceBackupGroupRun::class)->handle($run);
        $this->assertSame('success', $run->fresh()->status);
    }

    public function test_paused_group_cancels_frozen_children_without_dispatch_or_notification(): void
    {
        [$host] = $this->registered();
        $run = $this->groupRun([$host, $host], starts: false);
        $run->group->update(['status' => BackupJobGroup::STATUS_PAUSED]);
        $this->artisan('volumevault:dispatch-queued-runs')->assertSuccessful();
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertSame(2, $run->memberRuns()->where('status', 'cancelled')->count());
        $this->assertDatabaseCount('agent_operations', 0);
        $this->assertDatabaseCount('run_finalizations', 0);
    }

    public function test_coordinator_recovers_after_current_member_is_committed_but_publication_fails(): void
    {
        [$host, $body, $token] = $this->registered();
        $run = $this->groupRun([$host, $host]);
        $this->mock(DispatchAgentOperation::class)->shouldReceive('handle')->once()->andThrow(new \RuntimeException('Publication interrupted'));
        try {
            app(AdvanceBackupGroupRun::class)->handle($run);
            $this->fail('Expected interrupted publication.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Publication interrupted', $exception->getMessage());
        }
        $this->assertSame($run->member_run_ids[0], $run->fresh()->current_member_run_id);
        $this->assertDatabaseCount('agent_operations', 0);
        $this->app->forgetInstance(DispatchAgentOperation::class);
        $this->artisan('volumevault:dispatch-queued-runs')->assertSuccessful();
        $operation = $this->pull($body, $token);
        $this->assertSame($run->member_run_ids[0], AgentOperation::findOrFail($operation['id'])->backup_run_id);
        $this->assertDatabaseCount('agent_operations', 1);
        $this->assertSame(2, $run->memberRuns()->count());
    }

    public function test_maintenance_rejects_group_admission_without_partial_children(): void
    {
        [$host] = $this->registered();
        $host->forceFill(['maintenance_requested_at' => now()])->save();
        try {
            $this->groupRun([$host, $host], starts: false);
            $this->fail('Maintenance must block admission.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('docker_host_id', $exception->errors());
        }
        $this->assertDatabaseCount('backup_group_runs', 0);
        $this->assertDatabaseCount('backup_runs', 0);
        $this->assertDatabaseCount('agent_operations', 0);
    }

    public function test_paused_current_member_is_counted_failed_without_stalling_the_group(): void
    {
        [$host, $body, $token] = $this->registered();
        $run = $this->groupRun([$host, $host]);
        app(AdvanceBackupGroupRun::class)->handle($run);
        $first = $run->memberRuns()->orderBy('id')->firstOrFail();
        $first->job->update(['status' => BackupJob::STATUS_PAUSED]);
        app(AdvanceBackupGroupRun::class)->handle($run);
        $this->assertSame('cancelled', $first->fresh()->status);
        $operation = $this->pull($body, $token);
        $this->complete($body, $token, $operation)->assertOk();
        app(AdvanceBackupGroupRun::class)->handle($run);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(1, $run->fresh()->failed_members);
        $this->assertSame(1, $run->fresh()->succeeded_members);
        $this->assertSame(BackupJob::STATUS_PAUSED, $first->job->fresh()->status);
    }

    private function groupRun(array $hosts, string $policy = BackupJobGroup::FAILURE_POLICY_CONTINUE, bool $starts = true): BackupGroupRun
    {
        $this->mock(SendShoutrrrNotification::class)->shouldReceive('sendGroupRunStartedToChannel')->times($starts ? 1 : 0);
        $group = BackupJobGroup::create([
            'name' => 'Multi-host group', 'schedule_type' => 'daily', 'schedule_config' => ['time' => '02:00'],
            'status' => 'active', 'failure_policy' => $policy, 'notifications_enabled' => true,
        ]);
        $channel = NotificationChannel::create(['name' => 'Group', 'service' => NotificationChannel::SERVICE_ADVANCED, 'notification_level' => NotificationChannel::LEVEL_INFO, 'is_active' => true, 'url' => 'ntfy://notify.test/group']);
        $group->notificationChannels()->attach($channel);
        foreach ($hosts as $host) {
            $seed = $this->backup($host);
            $seed->job->update(['backup_job_group_id' => $group->id, 'notifications_enabled' => true]);
            $seed->delete();
        }

        return app(CreateBackupGroupRun::class)->handle($group, BackupGroupRun::TRIGGER_MANUAL);
    }

    /** @return array{DockerHost, string, array<string, mixed>} */
    private function pending(?DockerHost $host = null): array
    {
        $host ??= DockerHost::factory()->create();
        $installation = app(AgentRegistry::class)->issueEnrollment($host);
        preg_match('/VOLUMEVAULT_AGENT_ENROLLMENT_TOKEN=([^\x27 ]+)/', $installation['command'], $matches);

        return [$host, $matches[1], ['instance_id' => (string) Str::uuid(), 'credential' => bin2hex(random_bytes(32)), 'version' => 'test-1.0', 'protocol_version' => 1, 'capabilities' => ['inventory-v1', 'backup-v1', 'restore-v1']]];
    }

    /** @return array{DockerHost, array<string, mixed>, string} */
    private function registered(): array
    {
        [$host, $enrollment, $body] = $this->pending();
        $this->sendAgent('enroll', $enrollment, $body)->assertOk();
        $this->sendAgent('heartbeat', $host->uuid.'.'.$body['credential'], [...$body, 'docker_status' => 'ready', 'active_operations' => 0])->assertOk();

        return [$host, $body, $host->uuid.'.'.$body['credential']];
    }

    private function backup(DockerHost $host): BackupRun
    {
        $destination = BackupDestination::create(['name' => 'Network archive', 'provider' => 'aws_s3', 'region' => 'eu-central-1', 'bucket' => 'archives', 'access_key_id' => 'broker-access-secret', 'secret_access_key' => 'broker-private-secret', 'is_active' => true, 'use_path_style_endpoint' => false]);
        DockerVolume::firstOrCreate(['docker_host_id' => $host->id, 'name' => 'app_data'], ['exists' => true]);
        $job = BackupJob::create([
            'name' => 'Agent backup', 'docker_host_id' => $host->id, 'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
            'volume_name' => 'app_data', 'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY, 'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *', 'status' => BackupJob::STATUS_ACTIVE,
            'timezone' => 'UTC', 'stop_containers_before_backup' => false,
        ]);

        return app(CreateBackupRunRecord::class)->handle($job, ['status' => 'queued', 'trigger' => BackupRun::TRIGGER_MANUAL]);
    }

    private function enqueue(BackupRun|RestoreRun $run): AgentOperation
    {
        $this->assertTrue(app(DispatchAgentOperation::class)->handle($run));
        $this->travel(1)->seconds();

        return AgentOperation::where($run instanceof BackupRun ? 'backup_run_id' : 'restore_run_id', $run->id)->firstOrFail();
    }

    private function pull(array $body, string $token): array
    {
        $response = $this->sendAgent('operations/pull', $token, $body)->assertOk();
        $this->assertIsArray($response->json('operation'));

        $this->assertStringNotContainsString('broker-private-secret', $response->getContent());
        $this->assertStringNotContainsString('broker-access-secret', $response->getContent());

        return app(AgentOperationEnvelope::class)->open($response->json('operation'), $token);
    }

    private function operationResult(array $changes = []): array
    {
        return [...['status' => 'success', 'logs' => 'archive complete', 'backup_key' => 'archives/backup.tar.gz', 'backup_size_bytes' => 1234, 'cleanup_complete' => true, 'finished_at' => now()->toIso8601String(), 'duration_seconds' => 7], ...$changes];
    }

    private function complete(array $body, string $token, array $operation, array $changes = []): TestResponse
    {
        return $this->sendAgent('operations/'.$operation['id'].'/complete', $token, [...$body, 'token' => $operation['token'], 'result' => $this->operationResult($changes)]);
    }

    private function sendAgent(string $endpoint, string $token, array $body): TestResponse
    {
        return $this->postJson('https://orchestrator.test:8443/agent/v1/'.$endpoint, $body, ['Authorization' => 'Bearer '.$token]);
    }
}
