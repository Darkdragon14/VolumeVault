<?php

namespace Tests\Feature;

use App\Actions\Backup\CreateBackupRunRecord;
use App\Actions\Backup\DeleteBackupJob;
use App\Actions\Runs\DispatchQueuedRun;
use App\Models\ActivityLog;
use App\Models\AgentOperation;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Models\DockerVolume;
use App\Models\NotificationChannel;
use App\Models\RestoreRun;
use App\Models\RunFinalization;
use App\Models\User;
use App\Services\Agents\AgentOperationEnvelope;
use App\Services\Agents\AgentOperationRedactor;
use App\Services\Agents\AgentOperationSpecification;
use App\Services\Agents\AgentRegistry;
use App\Services\Agents\AgentTlsIdentity;
use App\Services\Agents\DispatchAgentOperation;
use App\Services\Docker\DockerProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
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
        $this->assertDatabaseCount('run_finalizations', 1);
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
