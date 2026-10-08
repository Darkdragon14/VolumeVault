<?php

namespace Tests\Feature;

use App\Actions\Backup\CreateBackupRunRecord;
use App\Models\AgentOperation;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Models\DockerVolume;
use App\Models\RestoreRun;
use App\Services\Agents\AgentCompatibility;
use App\Services\Agents\AgentLifecycle;
use App\Services\Agents\AgentOperationBroker;
use App\Services\Agents\AgentRegistry;
use App\Services\Agents\AgentTlsIdentity;
use App\Services\Agents\DispatchAgentOperation;
use App\Services\Agents\ReconcileAgentOperations;
use App\Services\Docker\DockerProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AgentRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config(['volumevault.mode' => 'orchestrator', 'volumevault.agents.enabled' => true]);
        Queue::fake();
        $this->mock(DockerProcess::class)->shouldNotReceive('run');
        $this->mock(AgentTlsIdentity::class)->shouldReceive('caCertificate')->andReturn('public-ca');
    }

    public function test_assigned_outage_fails_once_and_cleanup_receipt_cannot_resurrect_run(): void
    {
        $host = $this->host();
        $run = $this->backup($host);
        app(DispatchAgentOperation::class)->handle($run);
        $broker = app(AgentOperationBroker::class);
        $assignment = $broker->pull($host);
        $this->travel(16)->minutes();
        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();
        $this->assertSame('failed', $run->refresh()->status);
        $operation = AgentOperation::findOrFail($assignment['id']);
        $this->assertSame('cancelled', $operation->status);
        $this->assertNull($operation->payload);
        $this->assertTrue(app(ReconcileAgentOperations::class)->cleanupPending($host));
        $this->assertGreaterThan(0, app(AgentLifecycle::class)->activeOperations($host));
        $finished = $run->finished_at;
        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();
        $this->assertTrue($run->refresh()->finished_at->equalTo($finished));
        $host->forceFill(['last_seen_at' => now()])->save();
        $next = $this->backup($host);
        app(DispatchAgentOperation::class)->handle($next);
        $this->assertNull($broker->pull($host));
        $receipt = ['status' => 'success', 'cleanup_complete' => true, 'logs' => 'late secret', 'duration_seconds' => 1, 'finished_at' => now()->toIso8601String()];
        $broker->complete($host, $assignment['id'], $assignment['token'], $receipt);
        $broker->complete($host, $assignment['id'], $assignment['token'], $receipt);
        $this->assertFalse(app(ReconcileAgentOperations::class)->cleanupPending($host));
        $this->assertSame('failed', $run->refresh()->status);
        $this->assertStringNotContainsString('late secret', $run->logs);
        $this->assertSame('running', $broker->pull($host) !== null ? $next->refresh()->status : 'missing');
    }

    public function test_recent_progress_and_recent_reconnection_preserve_healthy_work(): void
    {
        $host = $this->host();
        $run = $this->backup($host);
        app(DispatchAgentOperation::class)->handle($run);
        $assignment = app(AgentOperationBroker::class)->pull($host);
        $this->travel(14)->minutes();
        app(AgentOperationBroker::class)->progress($host, $assignment['id'], $assignment['token']);
        $pending = $this->backup($host);
        $this->travel(2)->minutes();
        $host->forceFill(['last_seen_at' => now()])->save();
        app(ReconcileAgentOperations::class)->handle();
        $this->assertSame('running', $run->refresh()->status);
        $this->assertSame('queued', $pending->refresh()->status);
    }

    #[DataProvider('unavailableHosts')]
    public function test_unavailable_queued_runs_fail_with_and_without_operation(bool $assigned, bool $incompatible): void
    {
        $host = $this->host();
        $run = $this->backup($host);
        if ($assigned) {
            app(DispatchAgentOperation::class)->handle($run);
        }
        $this->travel(16)->minutes();
        if ($incompatible) {
            $host->forceFill(['agent_protocol_version' => 99, 'last_seen_at' => now()])->save();
        }
        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();
        $this->assertSame('failed', $run->refresh()->status);
        $this->assertSame('error', $run->job->refresh()->status);
        $this->assertFalse(app(ReconcileAgentOperations::class)->cleanupPending($host));
    }

    public static function unavailableHosts(): array
    {
        return ['no operation offline' => [false, false], 'pending offline' => [true, false],
            'no operation incompatible' => [false, true], 'pending incompatible' => [true, true]];
    }

    public function test_intentional_maintenance_preserves_pending_and_undispatched_runs(): void
    {
        $host = $this->host();
        $pending = $this->backup($host);
        app(DispatchAgentOperation::class)->handle($pending);
        $queued = $this->backup($host);
        $host->forceFill(['maintenance_requested_at' => now()])->save();
        $this->travel(60)->minutes();
        app(ReconcileAgentOperations::class)->handle();
        $this->assertSame('queued', $pending->refresh()->status);
        $this->assertSame('queued', $queued->refresh()->status);
    }

    #[DataProvider('lifecycleChanges')]
    public function test_revoke_and_reenrollment_cancel_old_assignments_and_queued_work(string $change): void
    {
        $host = $this->host();
        $run = $this->backup($host);
        app(DispatchAgentOperation::class)->handle($run);
        $assignment = app(AgentOperationBroker::class)->pull($host);
        $queued = $this->backup($host);
        app(AgentRegistry::class)->{$change}($host);
        $this->assertSame('cancelled', AgentOperation::findOrFail($assignment['id'])->status);
        app(ReconcileAgentOperations::class)->handle();
        $this->assertSame('failed', $run->refresh()->status);
        $this->assertSame('failed', $queued->refresh()->status);
        $this->assertTrue(app(ReconcileAgentOperations::class)->cleanupPending($host));
    }

    public static function lifecycleChanges(): array
    {
        return ['revocation' => ['revoke'], 'new enrollment' => ['issueEnrollment']];
    }

    public function test_replaced_instance_cannot_confirm_old_cleanup_and_manual_confirmation_is_guarded(): void
    {
        $host = $this->host();
        $run = $this->backup($host);
        app(DispatchAgentOperation::class)->handle($run);
        $assignment = app(AgentOperationBroker::class)->pull($host);
        $host->forceFill(['agent_instance_id' => (string) Str::uuid()])->save();
        app(ReconcileAgentOperations::class)->handle();
        $this->assertSame('failed', $run->refresh()->status);
        try {
            app(AgentOperationBroker::class)->complete($host, $assignment['id'], $assignment['token'], ['cleanup_complete' => true]);
            $this->fail('New instance confirmed cleanup for the old instance.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $this->artisan('volumevault:resolve-agent-cleanup', ['host' => $host->id])->assertFailed();
        $this->artisan('volumevault:resolve-agent-cleanup', ['host' => $host->id, '--force' => true])->assertFailed();
        $host->forceFill(['maintenance_requested_at' => now(), 'agent_active_operations' => 1])->save();
        $this->artisan('volumevault:resolve-agent-cleanup', ['host' => $host->id, '--force' => true])->assertSuccessful();
        $this->assertFalse(app(ReconcileAgentOperations::class)->cleanupPending($host));
        app(AgentOperationBroker::class)->complete($host, $assignment['id'], $assignment['token'], ['cleanup_complete' => true]);
        $this->assertSame('failed', $run->refresh()->status);
    }

    public function test_restore_without_operation_fails_when_target_is_offline(): void
    {
        $host = $this->host();
        $backup = $this->backup($host);
        $restore = RestoreRun::create(['backup_job_id' => $backup->backup_job_id, 'backup_destination_id' => $backup->job->backup_destination_id,
            'source_docker_host_id' => $host->id, 'target_docker_host_id' => $host->id, 'status' => 'queued',
            'selected_backup_key' => 'archive.tar.gz', 'source_volume_name' => 'app_data', 'target_volume_name' => 'restored', 'mode' => 'new_volume']);
        $this->travel(16)->minutes();
        app(ReconcileAgentOperations::class)->handle();
        $this->assertSame('failed', $restore->refresh()->status);
    }

    #[DataProvider('operationStates')]
    public function test_persistent_docker_unavailability_expires_work_despite_fresh_heartbeats(string $state): void
    {
        $host = $this->host();
        $run = $this->backup($host);
        $assignment = null;
        if ($state !== 'undispatched') {
            app(DispatchAgentOperation::class)->handle($run);
        }
        if ($state === 'running') {
            $assignment = app(AgentOperationBroker::class)->pull($host);
        }
        $this->heartbeat($host, 'unavailable');
        $unavailableAt = $host->refresh()->agent_docker_unavailable_at;
        $this->travel(14)->minutes();
        $this->heartbeat($host, 'unavailable');
        app(ReconcileAgentOperations::class)->handle();
        $this->assertSame($state === 'running' ? 'running' : 'queued', $run->refresh()->status);
        $this->travel(2)->minutes();
        $this->heartbeat($host, 'unavailable');
        if ($assignment !== null) {
            app(AgentOperationBroker::class)->progress($host, $assignment['id'], $assignment['token']);
        }
        app(ReconcileAgentOperations::class)->handle();
        $this->assertTrue($host->refresh()->agent_docker_unavailable_at->equalTo($unavailableAt));
        $this->assertSame('failed', $run->refresh()->status);
        $this->assertSame($state === 'running', app(ReconcileAgentOperations::class)->cleanupPending($host));
    }

    public static function operationStates(): array
    {
        return ['undispatched' => ['undispatched'], 'pending' => ['pending'], 'running' => ['running']];
    }

    public function test_recent_docker_outage_on_old_queued_work_gets_a_full_grace_period_and_recovery_resets_it(): void
    {
        $host = $this->host();
        $run = $this->backup($host);
        $this->travel(60)->minutes();
        $this->heartbeat($host, 'unavailable');
        app(ReconcileAgentOperations::class)->handle();
        $this->assertSame('queued', $run->refresh()->status);
        $this->travel(14)->minutes();
        $this->heartbeat($host, 'ready');
        $this->assertNull($host->refresh()->agent_docker_unavailable_at);
        $this->travel(2)->minutes();
        $this->heartbeat($host, 'unavailable');
        app(ReconcileAgentOperations::class)->handle();
        $this->assertSame('queued', $run->refresh()->status);
        $this->assertSame(now()->timestamp, $host->refresh()->agent_docker_unavailable_at->timestamp);
    }

    public function test_upgraded_unavailable_reports_get_a_new_grace_period_and_maintenance_is_exempt(): void
    {
        $host = $this->host();
        $run = $this->backup($host);
        $this->travel(60)->minutes();
        $host->forceFill(['docker_status' => 'unavailable', 'agent_docker_unavailable_at' => null, 'last_seen_at' => now()])->save();
        app(ReconcileAgentOperations::class)->handle();
        $this->assertSame('queued', $run->refresh()->status);
        $this->assertNotNull($host->refresh()->agent_docker_unavailable_at);
        $host->forceFill(['maintenance_requested_at' => now()])->save();
        $this->travel(16)->minutes();
        $this->heartbeat($host, 'unavailable');
        app(ReconcileAgentOperations::class)->handle();
        $this->assertSame('queued', $run->refresh()->status);
    }

    private function heartbeat(DockerHost $host, string $status): void
    {
        app(AgentRegistry::class)->heartbeat($host, ['instance_id' => $host->agent_instance_id, 'version' => 'test',
            'docker_status' => $status, 'protocol_version' => 1, 'capabilities' => AgentCompatibility::CAPABILITIES]);
    }

    private function host(): DockerHost
    {
        return DockerHost::factory()->create(['agent_registered_at' => now(), 'last_seen_at' => now(), 'agent_protocol_version' => 1,
            'agent_capabilities' => AgentCompatibility::CAPABILITIES, 'agent_instance_id' => (string) Str::uuid(),
            'agent_token_hash' => hash('sha256', str_repeat('a', 64)), 'agent_active_operations' => 0]);
    }

    private function backup(DockerHost $host): BackupRun
    {
        $destination = BackupDestination::create(['name' => 'Network archive', 'provider' => 'aws_s3', 'region' => 'eu-central-1',
            'bucket' => 'archives', 'access_key_id' => 'access', 'secret_access_key' => 'secret', 'is_active' => true]);
        DockerVolume::firstOrCreate(['docker_host_id' => $host->id, 'name' => 'app_data'], ['exists' => true]);
        $job = BackupJob::create(['name' => 'Agent backup', 'docker_host_id' => $host->id, 'source_type' => 'docker_volume', 'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id, 'status' => 'active', 'schedule_type' => 'daily',
            'schedule_config' => ['time' => '02:00'], 'timezone' => 'UTC']);

        return app(CreateBackupRunRecord::class)->handle($job, ['status' => 'queued', 'trigger' => 'manual']);
    }
}
