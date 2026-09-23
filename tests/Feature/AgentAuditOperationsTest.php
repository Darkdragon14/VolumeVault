<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Actions\Backup\CreateBackupRunRecord;
use App\Actions\Runs\ProcessRunFinalization;
use App\Models\AgentOperation;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Models\DockerVolume;
use App\Models\NotificationChannel;
use App\Models\RunFinalization;
use App\Models\User;
use App\Services\Agents\AgentCompatibility;
use App\Services\Agents\AgentOperationBroker;
use App\Services\Agents\AgentOperationSpecification;
use App\Services\Agents\DispatchAgentOperation;
use App\Services\BackupDestinations\DestinationOperations;
use App\Services\BackupDestinations\DestinationStorage;
use App\Services\BackupDestinations\ExecuteDestinationOperation;
use App\Services\BackupDestinations\ListBackupObjects;
use App\Services\Notifications\SendShoutrrrNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AgentAuditOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
        config(['volumevault.mode' => 'orchestrator']);
    }

    private function host(): DockerHost
    {
        return DockerHost::factory()->create([
            'driver' => 'agent', 'agent_registered_at' => now(), 'agent_instance_id' => (string) Str::uuid(),
            'agent_protocol_version' => 1, 'agent_capabilities' => AgentCompatibility::CAPABILITIES,
            'agent_token_hash' => hash('sha256', 'test-token'), 'agent_active_operations' => 0,
        ]);
    }

    private function destination(array $attributes = []): BackupDestination
    {
        return BackupDestination::create([...[
            'name' => 'archives', 'provider' => 'aws_s3', 'region' => 'eu-central-1', 'bucket' => 'original-bucket',
            'access_key_id' => 'archive', 'secret_access_key' => 'very-private-secret', 'is_active' => true,
        ], ...$attributes]);
    }

    private function backup(DockerHost $host): BackupRun
    {
        $destination = $this->destination();
        DockerVolume::create(['docker_host_id' => $host->id, 'name' => 'app_data', 'exists' => true]);
        $job = BackupJob::create([
            'name' => 'Agent backup', 'docker_host_id' => $host->id, 'source_type' => 'docker_volume',
            'volume_name' => 'app_data', 'backup_destination_id' => $destination->id,
            'schedule_type' => 'daily', 'schedule_config' => ['time' => '02:00'], 'cron_expression' => '0 2 * * *',
            'status' => 'active', 'timezone' => 'UTC', 'notifications_enabled' => true,
        ]);
        $channel = NotificationChannel::create(['name' => 'Info', 'service' => 'advanced', 'notification_level' => 'info', 'is_active' => true, 'url' => 'ntfy://notify.test/test']);
        $job->notificationChannels()->attach($channel);

        return app(CreateBackupRunRecord::class)->handle($job, ['status' => 'queued', 'trigger' => 'manual']);
    }

    private function receipt(?array $data = null): array
    {
        $result = ['status' => 'success', 'logs' => '', 'cleanup_complete' => true, 'finished_at' => now()->toIso8601String(), 'duration_seconds' => 1];

        return $data === null ? $result : [...$result, 'data' => $data];
    }

    public function test_start_is_atomic_deduplicated_and_recipient_selection_survives_job_edits_and_fast_completion(): void
    {
        $host = $this->host();
        $run = $this->backup($host);
        $error = NotificationChannel::create(['name' => 'Errors', 'service' => 'advanced', 'notification_level' => 'error', 'is_active' => true, 'url' => 'ntfy://notify.test/errors']);
        $run->job->notificationChannels()->attach($error);
        app(DispatchAgentOperation::class)->handle($run);
        $this->assertDatabaseCount('run_finalizations', 0);
        $broker = app(AgentOperationBroker::class);
        $operation = $broker->pull($host);
        $this->assertSame($operation, $broker->pull($host));
        $this->assertSame(1, RunFinalization::where('type', 'started_notification')->count());
        $finalization = RunFinalization::firstOrFail();
        $run->job->notificationChannels()->detach();
        $broker->complete($host, $operation['id'], $operation['token'], [...$this->receipt(), 'backup_key' => 'backup.tar.gz', 'backup_size_bytes' => 12]);
        $this->mock(SendShoutrrrNotification::class)->shouldReceive('sendBackupRunStartedToChannel')->once()
            ->withArgs(fn ($sentRun, $channel) => $sentRun->id === $run->id && $channel->id === $finalization->notification_channel_id);
        app(ProcessRunFinalization::class)->handle($finalization->id);
        app(ProcessRunFinalization::class)->handle($finalization->id);
        $this->assertSame('completed', $finalization->fresh()->status);
    }

    public function test_metadata_retry_is_durable_frozen_and_never_replays_backup(): void
    {
        $host = $this->host();
        $run = $this->backup($host);
        app(DispatchAgentOperation::class)->handle($run);
        $broker = app(AgentOperationBroker::class);
        $backup = $broker->pull($host);
        $broker->complete($host, $backup['id'], $backup['token'], $this->receipt());
        $metadata = RunFinalization::where('type', 'archive_metadata')->firstOrFail();
        $finished = RunFinalization::where('type', 'finished_notification')->firstOrFail();
        $this->assertTrue($run->fresh()->archive_metadata_pending);
        $this->assertNull(AgentOperation::find($backup['id'])->payload);
        $this->assertStringNotContainsString('very-private-secret', DB::table('run_finalizations')->where('id', $metadata->id)->value('remote_metadata_payload'));
        $run->job->destination->update(['bucket' => 'edited-bucket']);
        $run->job->update(['volume_name' => 'changed-source']);
        app(ProcessRunFinalization::class)->handle($finished->id);
        $this->assertSame(0, $finished->fresh()->attempts);
        app(ProcessRunFinalization::class)->handle($metadata->id);
        $first = $broker->pull($host);
        $this->assertSame('metadata', $first['spec']['action']);
        $this->assertSame('original-bucket', $first['spec']['destination']['bucket']);
        $this->assertSame($backup['spec']['run']['backup_filename'], $first['spec']['archive']['filename']);
        app(AgentOperationSpecification::class)->validate($first);
        $broker->complete($host, $first['id'], $first['token'], [...$this->receipt(), 'status' => 'failed', 'data' => null]);
        $this->travel(61)->seconds();
        app(ProcessRunFinalization::class)->handle($metadata->id);
        $this->assertSame('failed', $metadata->fresh()->status);
        $this->travel(61)->seconds();
        app()->forgetInstance(ProcessRunFinalization::class);
        app(ProcessRunFinalization::class)->handle($metadata->id);
        $second = app(AgentOperationBroker::class)->pull($host);
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame($first['spec'], $second['spec']);
        $this->assertSame(1, AgentOperation::where('kind', 'backup')->count());
        $broker->complete($host, $second['id'], $second['token'], $this->receipt(['backup_key' => 'exact.tar.gz', 'backup_size_bytes' => 500]));
        $this->travel(61)->seconds();
        app(ProcessRunFinalization::class)->handle($metadata->id);
        $this->assertSame('completed', $metadata->fresh()->status);
        $this->assertFalse($run->fresh()->archive_metadata_pending);
        $this->assertSame(500, $run->fresh()->backup_size_bytes);
        $this->assertNull($metadata->fresh()->remote_metadata_payload);
    }

    public function test_unsaved_host_key_discovery_uses_only_selected_agent_and_has_scoped_read_endpoint(): void
    {
        $this->mock(DestinationStorage::class)->shouldNotReceive('probeHostKey');
        $host = $this->host();
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin, ['read']);
        $this->postJson('/api/v1/destinations/host-key', ['host' => 'sftp.test', 'docker_host_id' => $host->id])->assertForbidden();
        Sanctum::actingAs($admin, ['write']);
        $id = $this->postJson('/api/v1/destinations/host-key', ['host' => 'sftp.test', 'port' => 2222, 'docker_host_id' => $host->id])->assertAccepted()->json('data.id');
        $path = '/api/v1/destinations/host-key/operations/'.$id;
        $this->getJson($path)->assertForbidden();
        $operation = app(AgentOperationBroker::class)->pull($host);
        app(AgentOperationSpecification::class)->validate($operation);
        $this->assertSame(['provider' => 'ssh', 'host' => 'sftp.test', 'port' => 2222], $operation['spec']['destination']);
        $this->assertSame($operation, app(AgentOperationBroker::class)->pull($host));
        app(AgentOperationBroker::class)->complete($host, $id, $operation['token'], $this->receipt(['key' => 'ssh-ed25519 public', 'fingerprint' => 'SHA256:public']));
        Sanctum::actingAs($admin, ['read']);
        $this->getJson($path)->assertOk()->assertJsonPath('data.result.data.key', 'ssh-ed25519 public')->assertJsonPath('data.endpoint.port', 2222);
        Sanctum::actingAs(User::factory()->create(['role' => 'viewer']), ['read']);
        $this->getJson($path)->assertForbidden();
    }

    public function test_automatic_usage_uses_designated_host_and_rejects_old_executor_and_locator_receipts(): void
    {
        $first = $this->host();
        $second = $this->host();
        $destination = $this->destination(['storage_measurement_host_id' => $first->id]);
        $operations = app(DestinationOperations::class);
        $this->assertSame(1, $operations->hostId($destination));
        try {
            app(DestinationStorage::class)->storageUsage($destination);
            $this->fail('Pending measurement returned data.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('pending or stale', $exception->getMessage());
        }
        $operation = app(AgentOperationBroker::class)->pull($first);
        app(AgentOperationBroker::class)->complete($first, $operation['id'], $operation['token'], $this->receipt(['used_bytes' => 123, 'object_count' => 1]));
        $this->assertSame(123, app(DestinationStorage::class)->storageUsage($destination)['used_bytes']);
        $fingerprint = $destination->storageMeasurementFingerprint();
        $destination->update(['storage_measurement_host_id' => $second->id]);
        $this->assertNotSame($fingerprint, $destination->storageMeasurementFingerprint());
        try {
            $operations->usage($destination);
            $this->fail('Old executor receipt reused.');
        } catch (\RuntimeException) {
            $this->assertSame($second->id, AgentOperation::where('status', 'pending')->firstOrFail()->docker_host_id);
        }
        $destination->update(['bucket' => 'different']);
        $this->expectException(\RuntimeException::class);
        $operations->usage($destination);
    }

    public function test_metadata_executor_never_invents_dropbox_identity(): void
    {
        $destination = $this->destination(['provider' => 'dropbox']);
        $this->mock(ListBackupObjects::class)->shouldNotReceive('findByFilename');
        $result = app(ExecuteDestinationOperation::class)->handle(['action' => 'metadata', 'limit' => 1,
            'destination' => app(DispatchAgentOperation::class)->destination($destination),
            'archive' => ['filename' => 'backup.tar.gz', 'key' => null, 'size' => null]]);

        $this->assertSame('failed', $result['status']);
        $this->assertNull($result['data']);
    }

    public function test_metadata_docker_volume_lookup_uses_the_operation_owned_helper_and_cleans_it(): void
    {
        $id = (string) Str::uuid();
        $destination = $this->destination(['provider' => 'docker_volume', 'settings' => ['volume_name' => 'archives']]);
        $storage = $this->mock(DestinationStorage::class);
        $storage->shouldReceive('useOperationHelper')->once()->with(\App\Actions\Docker\CleanupDestinationOperationHelper::name($id));
        $storage->shouldReceive('findBackupObjectByFilename')->once()->withArgs(fn ($destination, $filename) => $destination->provider === 'docker_volume' && $filename === 'original.tar.gz')
            ->andReturn(['key' => 'original.tar.gz', 'size' => 42]);
        $storage->shouldReceive('useOperationHelper')->once()->with(null);
        $this->mock(\App\Actions\Docker\CleanupDestinationOperationHelper::class)->shouldReceive('handle')->once()->with($id)->andReturnTrue();
        $result = app(ExecuteDestinationOperation::class)->handle(['action' => 'metadata', 'limit' => 1,
            'destination' => app(DispatchAgentOperation::class)->destination($destination),
            'archive' => ['filename' => 'original.tar.gz', 'key' => null, 'size' => null]], $id);
        $this->assertSame('success', $result['status']);
        $this->assertTrue($result['cleanup_complete']);
        $this->assertSame(42, $result['data']['backup_size_bytes']);
    }

    public function test_metadata_failures_exhaust_after_five_read_only_operations(): void
    {
        $host = $this->host();
        $run = $this->backup($host);
        app(DispatchAgentOperation::class)->handle($run);
        $broker = app(AgentOperationBroker::class);
        $backup = $broker->pull($host);
        $broker->complete($host, $backup['id'], $backup['token'], $this->receipt());
        $metadata = RunFinalization::where('type', 'archive_metadata')->firstOrFail();
        foreach (range(1, RunFinalization::MAX_ATTEMPTS) as $attempt) {
            app(ProcessRunFinalization::class)->handle($metadata->id);
            $operation = $broker->pull($host);
            $broker->complete($host, $operation['id'], $operation['token'], [...$this->receipt(), 'status' => 'failed', 'data' => null]);
            $this->travel(61)->seconds();
            app(ProcessRunFinalization::class)->handle($metadata->id);
            $this->travel(61)->minutes();
        }
        app(ProcessRunFinalization::class)->handle($metadata->id);
        $this->assertSame(RunFinalization::MAX_ATTEMPTS, AgentOperation::where('destination_action', 'metadata')->count());
        $this->assertSame('failed', $metadata->fresh()->status);
        $this->assertNull($metadata->fresh()->available_at);
        $this->assertFalse($run->fresh()->archive_metadata_pending);
        $this->assertSame(1, AgentOperation::where('kind', 'backup')->count());
    }

    public function test_pending_offline_and_group_member_backups_do_not_create_standalone_starts(): void
    {
        $host = $this->host();
        $run = $this->backup($host);
        app(DispatchAgentOperation::class)->handle($run);
        $this->travel(2)->hours();
        $this->assertSame(0, RunFinalization::count());
        $group = \App\Models\BackupJobGroup::create(['name' => 'Group', 'schedule_type' => 'daily', 'schedule_config' => ['time' => '02:00'], 'status' => 'active', 'failure_policy' => 'continue']);
        $run->job->update(['backup_job_group_id' => $group->id]);
        app(AgentOperationBroker::class)->pull($host);
        $this->assertSame('running', $run->fresh()->status);
        $this->assertSame(0, RunFinalization::where('type', 'started_notification')->count());
    }

    public function test_old_agent_metadata_failure_is_explicit_bounded_and_unblocks_finished_notification(): void
    {
        $host = $this->host();
        $host->forceFill(['agent_capabilities' => ['inventory-v1', 'backup-v1']])->save();
        $run = $this->backup($host);
        app(DispatchAgentOperation::class)->handle($run);
        $operation = app(AgentOperationBroker::class)->pull($host);
        app(AgentOperationBroker::class)->complete($host, $operation['id'], $operation['token'], $this->receipt());
        $metadata = RunFinalization::where('type', 'archive_metadata')->firstOrFail();
        foreach (range(1, RunFinalization::MAX_ATTEMPTS) as $attempt) {
            app(ProcessRunFinalization::class)->handle($metadata->id);
            $this->travel(61)->minutes();
        }
        $metadata->refresh();
        $this->assertSame('failed', $metadata->status);
        $this->assertNull($metadata->available_at);
        $this->assertNull($metadata->remote_metadata_payload);
        $this->assertStringContainsString('archive-metadata-v1', $metadata->last_error);
        $this->assertFalse($run->fresh()->archive_metadata_pending);
        $this->assertSame(0, AgentOperation::where('kind', 'destination')->count());
        $sender = $this->mock(SendShoutrrrNotification::class);
        $sender->shouldReceive('sendBackupRunFinishedToChannel')->once();
        $sender->shouldReceive('sendBackupRunStartedToChannel')->once();
        app(ProcessRunFinalization::class)->handle(RunFinalization::where('type', 'started_notification')->firstOrFail()->id);
        app(ProcessRunFinalization::class)->handle(RunFinalization::where('type', 'finished_notification')->firstOrFail()->id);
    }

    public function test_metadata_wait_is_bounded_and_cancels_unclaimed_operation_without_replaying_backup(): void
    {
        $host = $this->host();
        $run = $this->backup($host);
        app(DispatchAgentOperation::class)->handle($run);
        $operation = app(AgentOperationBroker::class)->pull($host);
        app(AgentOperationBroker::class)->complete($host, $operation['id'], $operation['token'], $this->receipt());
        $metadata = RunFinalization::where('type', 'archive_metadata')->firstOrFail();
        app(ProcessRunFinalization::class)->handle($metadata->id);
        $this->assertSame(0, $metadata->fresh()->attempts);
        $this->travel(31)->minutes();
        app(ProcessRunFinalization::class)->handle($metadata->id);
        $this->assertSame('cancelled', AgentOperation::where('destination_action', 'metadata')->firstOrFail()->status);
        $this->assertStringContainsString('deadline', $metadata->fresh()->last_error);
        $this->assertSame(1, AgentOperation::where('kind', 'backup')->count());
    }

    public function test_host_key_old_capability_and_host_injection_are_rejected_without_central_probe(): void
    {
        $this->mock(DestinationStorage::class)->shouldNotReceive('probeHostKey');
        $host = $this->host();
        $this->actingAs(User::factory()->admin()->create());
        $this->postJson('/destinations/host-key', ['host' => 'server;touch /tmp/unsafe', 'docker_host_id' => $host->id])->assertUnprocessable();
        $host->forceFill(['agent_capabilities' => ['inventory-v1', 'destination-v1']])->save();
        $this->postJson('/destinations/host-key', ['host' => 'sftp.test', 'docker_host_id' => $host->id])->assertUnprocessable();
        $this->assertSame(0, AgentOperation::count());
    }

    public function test_storage_executor_is_saved_by_api_without_exposing_or_clearing_credentials(): void
    {
        $host = $this->host();
        Sanctum::actingAs(User::factory()->admin()->create(), ['read', 'write']);
        $data = ['name' => 'Archives', 'provider' => 'aws_s3', 'bucket' => 'archives', 'region' => 'eu-central-1',
            'access_key_id' => 'access', 'secret_access_key' => 'private', 'storage_measurement_host_id' => $host->id];
        $response = $this->postJson('/api/v1/destinations', $data)->assertCreated()->assertJsonPath('data.storage_measurement_host_id', $host->id);
        $id = $response->json('data.id');
        $this->assertArrayNotHasKey('secret_access_key', $response->json('data'));
        unset($data['access_key_id'], $data['secret_access_key'], $data['storage_measurement_host_id']);
        $this->putJson('/api/v1/destinations/'.$id, $data)->assertOk()->assertJsonPath('data.storage_measurement_host_id', $host->id);
        $this->assertSame('private', BackupDestination::findOrFail($id)->secret('secret_access_key'));
        $this->putJson('/api/v1/destinations/'.$id, [...$data, 'storage_measurement_host_id' => null])->assertOk()->assertJsonPath('data.storage_measurement_host_id', null);
        $host->forceFill(['agent_capabilities' => ['inventory-v1']])->save();
        $this->putJson('/api/v1/destinations/'.$id, [...$data, 'storage_measurement_host_id' => $host->id])->assertUnprocessable()->assertJsonValidationErrors('storage_measurement_host_id');
    }

    public function test_agent_only_network_stats_activate_preserve_and_resolve_alerts_with_fresh_executor_scoped_baselines(): void
    {
        $host = $this->host();
        $destination = $this->destination(['storage_measurement_host_id' => $host->id,
            'settings' => ['storage_limit_warning_bytes' => 100, 'storage_limit_critical_bytes' => 200]]);
        app(\App\Actions\Alerts\EnsureAlertRules::class)->handle();
        $rule = \App\Models\AlertRule::where('type', 'destination_storage_limit')->firstOrFail();
        $rule->update(['enabled' => true]);
        $checks = app(\App\Actions\Alerts\RunAllAlertChecks::class);
        $checks->handle($rule);
        $this->assertSame(0, \App\Models\Alert::count());
        $broker = app(AgentOperationBroker::class);
        $operation = $broker->pull($host);
        $broker->complete($host, $operation['id'], $operation['token'], $this->receipt(['used_bytes' => 150, 'object_count' => 2]));
        $checks->handle($rule);
        $alert = \App\Models\Alert::firstOrFail();
        $this->assertSame('active', $alert->status->value);
        $this->assertNull($alert->context['previous_used_bytes']);
        $this->travel(31)->minutes();
        $checks->handle($rule);
        $this->assertSame('active', $alert->fresh()->status->value);
        $old = $broker->pull($host);
        $destination->update(['bucket' => 'changed']);
        $broker->complete($host, $old['id'], $old['token'], $this->receipt(['used_bytes' => 0, 'object_count' => 0]));
        $checks->handle($rule);
        $this->assertSame('active', $alert->fresh()->status->value);
        $current = $broker->pull($host);
        $broker->complete($host, $current['id'], $current['token'], $this->receipt(['used_bytes' => 250, 'object_count' => 2]));
        $checks->handle($rule);
        $this->assertNull($alert->fresh()->context['previous_used_bytes']);
        $this->travel(31)->minutes();
        $checks->handle($rule);
        $current = $broker->pull($host);
        $broker->complete($host, $current['id'], $current['token'], $this->receipt(['used_bytes' => 0, 'object_count' => 0]));
        $checks->handle($rule);
        $this->assertSame('resolved', $alert->fresh()->status->value);
    }

    public function test_start_retry_and_finish_use_immutable_delivery_snapshot_in_order(): void
    {
        $host = $this->host();
        $run = $this->backup($host);
        $channel = $run->job->notificationChannels()->firstOrFail();
        $channel->update(['service' => 'webhook', 'url' => json_encode(['start' => 'generic://a.test/private/start', 'success' => 'generic://a.test/private/success'])]);
        app(DispatchAgentOperation::class)->handle($run);
        $broker = app(AgentOperationBroker::class);
        $operation = $broker->pull($host);
        $start = RunFinalization::where('type', 'started_notification')->firstOrFail();
        $channel->update(['service' => 'advanced', 'url' => 'ntfy://b.test/new-secret']);
        $broker->complete($host, $operation['id'], $operation['token'], [...$this->receipt(), 'backup_key' => 'archive.tar.gz', 'backup_size_bytes' => 1]);
        $finish = RunFinalization::where('type', 'finished_notification')->firstOrFail();
        $urls = [];
        $this->mock(\App\Services\Notifications\NativeShoutrrrProcess::class)->shouldReceive('send')->times(3)->andReturnUsing(function ($url) use (&$urls) {
            $urls[] = $url;

            return new \App\Services\Docker\DockerProcessResult([], count($urls) === 1 ? 1 : 0, '', '');
        });
        $processor = app(ProcessRunFinalization::class);
        $processor->handle($finish->id);
        $processor->handle($start->id);
        $processor->handle($finish->id);
        $this->assertSame(0, $finish->fresh()->attempts);
        $this->assertSame(0, $processor->dispatch([$finish->id]));
        $this->travel(61)->seconds();
        $processor->handle($start->id);
        $processor->handle($finish->id);
        $this->assertSame(['generic://a.test/private/start', 'generic://a.test/private/start', 'generic://a.test/private/success'], $urls);
        $this->assertSame('completed', $finish->fresh()->status);
        foreach ([$start, $finish] as $row) {
            $this->assertArrayNotHasKey('notification_snapshot', $row->fresh()->toArray());
            $this->assertStringNotContainsString('a.test/private', DB::table('run_finalizations')->where('id', $row->id)->value('notification_snapshot'));
        }
    }

    public function test_start_exhaustion_eventually_releases_finish(): void
    {
        $run = $this->backup($this->host());
        $creator = app(\App\Actions\Runs\CreateRunFinalizations::class);
        $startId = $creator->createBackupStartNotifications($run, $run->job)[0];
        $run->update(['status' => 'success']);
        $finishId = $creator->createBackupNotifications($run, $run->job)[0];
        $sender = $this->mock(SendShoutrrrNotification::class);
        $sender->shouldReceive('sendBackupRunStartedToChannel')->times(5)->andThrow(new \RuntimeException('Unavailable'));
        $sender->shouldReceive('sendBackupRunFinishedToChannel')->once();
        foreach (range(1, 5) as $attempt) {
            app(ProcessRunFinalization::class)->handle($finishId);
            $this->assertSame(0, RunFinalization::find($finishId)->attempts);
            app(ProcessRunFinalization::class)->handle($startId);
            $this->travel(61)->minutes();
        }
        app(ProcessRunFinalization::class)->handle($finishId);
        $this->assertNull(RunFinalization::find($startId)->available_at);
        $this->assertSame('completed', RunFinalization::find($finishId)->status);
    }

    public static function groupMetadataOutcomes(): array
    {
        return [['completed'], ['exhausted'], ['deleted']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('groupMetadataOutcomes')]
    public function test_group_can_advance_and_finish_but_notification_waits_for_frozen_member_metadata(string $outcome): void
    {
        $first = $this->backup($this->host());
        $second = $this->backup($this->host());
        $group = \App\Models\BackupJobGroup::create(['name' => 'Group', 'schedule_type' => 'daily', 'schedule_config' => ['time' => '02:00'], 'status' => 'running', 'failure_policy' => 'continue', 'notifications_enabled' => true]);
        $group->notificationChannels()->attach($first->job->notificationChannels()->firstOrFail());
        $groupRun = \App\Models\BackupGroupRun::create(['backup_job_group_id' => $group->id, 'status' => 'running', 'trigger' => 'manual', 'started_at' => now(), 'total_members' => 2]);
        $groupRun->forceFill(['member_run_ids' => [$first->id, $second->id], 'failure_policy_snapshot' => 'continue'])->save();
        foreach ([$first, $second] as $run) {
            $run->job->update(['backup_job_group_id' => $group->id]);
            $run->update(['backup_group_run_id' => $groupRun->id]);
        }
        $first->update(['status' => 'success', 'archive_metadata_pending' => true]);
        $creator = app(\App\Actions\Runs\CreateRunFinalizations::class);
        $metadata = $creator->createMetadata($first);
        app(\App\Actions\Backup\AdvanceBackupGroupRun::class)->handle($groupRun);
        $this->assertSame($second->id, $groupRun->fresh()->current_member_run_id);
        $second->update(['status' => 'success', 'archive_metadata_pending' => true]);
        $lastMetadata = $creator->createMetadata($second);
        $metadata->update(['status' => 'completed', 'available_at' => null]);
        app(\App\Actions\Backup\AdvanceBackupGroupRun::class)->handle($groupRun);
        $this->assertSame('success', $groupRun->fresh()->status);
        $finish = $groupRun->finalizations()->where('type', 'finished_notification')->firstOrFail();
        $groupRun->forceFill(['member_run_ids' => []])->save();
        $second->job->update(['backup_job_group_id' => null]);
        app(ProcessRunFinalization::class)->handle($finish->id);
        $this->assertSame(0, $finish->fresh()->attempts);
        $this->assertSame(0, app(ProcessRunFinalization::class)->dispatch([$finish->id]));
        if ($outcome === 'deleted') {
            DB::table('backup_runs')->where('id', $second->id)->delete();
        } else {
            $lastMetadata->update(['status' => $outcome === 'completed' ? 'completed' : 'failed', 'available_at' => null, 'attempts' => 5]);
        }
        $unrelated = $this->backup($this->host());
        $unrelated->update(['backup_group_run_id' => $groupRun->id]);
        $creator->createMetadata($unrelated);
        $this->mock(SendShoutrrrNotification::class)->shouldReceive('sendGroupRunFinishedToChannel')->once();
        app(ProcessRunFinalization::class)->handle($finish->id);
        $this->assertSame('completed', $finish->fresh()->status);
    }

    public function test_group_finish_waits_for_start_backoff_even_after_group_terminal_status(): void
    {
        $seed = $this->backup($this->host());
        $group = \App\Models\BackupJobGroup::create(['name' => 'Group', 'schedule_type' => 'daily', 'schedule_config' => ['time' => '02:00'], 'status' => 'active', 'failure_policy' => 'continue', 'notifications_enabled' => true]);
        $group->notificationChannels()->attach($seed->job->notificationChannels()->firstOrFail());
        $run = \App\Models\BackupGroupRun::create(['backup_job_group_id' => $group->id, 'status' => 'running', 'trigger' => 'manual']);
        $creator = app(\App\Actions\Runs\CreateRunFinalizations::class);
        $start = $creator->createGroupStartNotifications($run, $group)[0];
        $run->update(['status' => 'success']);
        $finish = $creator->createGroupNotifications($run, $group)[0];
        $sender = $this->mock(SendShoutrrrNotification::class);
        $sender->shouldReceive('sendGroupRunStartedToChannel')->once()->ordered()->andThrow(new \RuntimeException('Unavailable'));
        $sender->shouldReceive('sendGroupRunStartedToChannel')->once()->ordered()->withArgs(fn ($run) => $run->status === 'running');
        $sender->shouldReceive('sendGroupRunFinishedToChannel')->once()->ordered();
        app(ProcessRunFinalization::class)->handle($start);
        app(ProcessRunFinalization::class)->handle($finish);
        $this->assertSame(0, RunFinalization::find($finish)->attempts);
        $this->travel(61)->seconds();
        app(ProcessRunFinalization::class)->handle($start);
        app(ProcessRunFinalization::class)->handle($finish);
        $this->assertSame('completed', RunFinalization::find($finish)->status);
    }

    public function test_dropbox_metadata_uses_captured_id_and_persists_actual_size_from_http_receipt(): void
    {
        $host = $this->host();
        $run = $this->backup($host);
        $run->job->destination->update(['provider' => 'dropbox', 'secrets' => ['app_key' => 'app', 'app_secret' => 'secret', 'refresh_token' => 'refresh']]);
        $run->update(['backup_destination_provider' => 'dropbox', 'backup_destination_locator_fingerprint' => $run->job->destination->fresh()->locatorFingerprint()]);
        Http::fake([
            'api.dropboxapi.com/oauth2/token' => Http::response(['access_token' => 'access']),
            'api.dropboxapi.com/2/files/get_metadata' => Http::response(['.tag' => 'file', 'id' => 'id:captured', 'name' => 'renamed.tar.gz', 'size' => 9876]),
        ]);
        app(DispatchAgentOperation::class)->handle($run);
        $broker = app(AgentOperationBroker::class);
        $backup = $broker->pull($host);
        $broker->complete($host, $backup['id'], $backup['token'], [...$this->receipt(), 'backup_key' => 'id:captured', 'backup_size_bytes' => null]);
        $metadata = RunFinalization::where('type', 'archive_metadata')->firstOrFail();
        app(ProcessRunFinalization::class)->handle($metadata->id);
        $operation = $broker->pull($host);
        $result = app(ExecuteDestinationOperation::class)->handle($operation['spec'], $operation['id']);
        $this->assertSame('success', $result['status']);
        $broker->complete($host, $operation['id'], $operation['token'], $result);
        $this->travel(61)->seconds();
        app(ProcessRunFinalization::class)->handle($metadata->id);
        $this->assertSame(9876, $run->fresh()->backup_size_bytes);
        $this->assertSame('id:captured', $run->fresh()->backup_key);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/get_metadata') && $request['path'] === 'id:captured');
        Http::assertSentCount(2);
    }

    public static function restoreStartCases(): array
    {
        $cases = [];
        foreach (['new_volume', 'inplace'] as $mode) {
            foreach ([false, true] as $grouped) {
                foreach ([false, true] as $exhausted) {
                    $cases[] = [$mode, $grouped, $exhausted];
                }
            }
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('restoreStartCases')]
    public function test_remote_restore_start_is_frozen_deduplicated_and_ordered(string $mode, bool $grouped, bool $exhausted): void
    {
        $host = $this->host();
        $backup = $this->backup($host);
        $job = $backup->job;
        $channel = $job->notificationChannels()->firstOrFail();
        $channel->update(['service' => 'webhook', 'url' => json_encode(['start' => 'generic://restore.test/start', 'success' => 'generic://restore.test/finish'])]);
        $error = NotificationChannel::create(['name' => 'Errors', 'service' => 'advanced', 'notification_level' => 'error', 'is_active' => true, 'url' => 'ntfy://errors.test']);
        if ($grouped) {
            $group = \App\Models\BackupJobGroup::create(['name' => 'Restore group', 'schedule_type' => 'daily', 'schedule_config' => ['time' => '02:00'], 'status' => 'active', 'failure_policy' => 'continue', 'notifications_enabled' => true]);
            $group->notificationChannels()->attach([$channel->id, $error->id]);
            $job->update(['backup_job_group_id' => $group->id, 'notifications_enabled' => false]);
            $job->notificationChannels()->detach();
        } else {
            $job->notificationChannels()->attach($error);
        }
        $restore = \App\Models\RestoreRun::create([
            'backup_job_id' => $job->id, 'backup_destination_id' => $job->backup_destination_id,
            'source_docker_host_id' => $host->id, 'target_docker_host_id' => $host->id,
            'source_volume_name' => 'app_data', 'target_volume_name' => $mode === 'inplace' ? 'app_data' : 'restored',
            'mode' => $mode, 'confirmation_text' => 'app_data', 'selected_backup_key' => 'archive.tar.gz',
            'status' => 'queued', 'backup_before_overwrite' => false,
        ]);
        app(DispatchAgentOperation::class)->handle($restore);
        $this->travel(1)->hours();
        $this->assertSame(0, RunFinalization::count());
        $broker = app(AgentOperationBroker::class);
        $operation = $broker->pull($host);
        $this->assertSame($operation, $broker->pull($host));
        $this->assertStringNotContainsString('restore.test', json_encode($operation));
        $start = RunFinalization::where('restore_run_id', $restore->id)->sole();
        $this->assertSame('started_notification', $start->type);
        $this->assertSame($channel->id, $start->notification_channel_id);
        $channel->update(['url' => json_encode(['start' => 'generic://changed.test/start', 'success' => 'generic://changed.test/finish'])]);
        $broker->complete($host, $operation['id'], $operation['token'], $this->receipt());
        $finish = RunFinalization::where('restore_run_id', $restore->id)->where('type', 'finished_notification')->sole();
        $urls = [];
        $startCalls = 0;
        $this->mock(\App\Services\Notifications\NativeShoutrrrProcess::class)->shouldReceive('send')->times($exhausted ? 6 : 3)->andReturnUsing(function ($url, $title, $message) use (&$urls, &$startCalls, $exhausted) {
            $urls[] = $url;
            $failed = false;
            if (str_ends_with($url, '/start')) {
                $startCalls++;
                $this->assertStringNotContainsString('succeeded', strtolower($title));
                $failed = $exhausted || $startCalls === 1;
            }

            return new \App\Services\Docker\DockerProcessResult([], $failed ? 1 : 0, '', '');
        });
        foreach (range(1, $exhausted ? 5 : 2) as $attempt) {
            app(ProcessRunFinalization::class)->handle($finish->id);
            $this->assertSame(0, $finish->fresh()->attempts);
            app(ProcessRunFinalization::class)->handle($start->id);
            $this->travel(61)->minutes();
        }
        app(ProcessRunFinalization::class)->handle($finish->id);
        $this->assertSame([...array_fill(0, $exhausted ? 5 : 2, 'generic://restore.test/start'), 'generic://restore.test/finish'], $urls);
        $this->assertSame('completed', $finish->fresh()->status);
        $this->assertNull($start->fresh()->available_at);
    }

    public function test_sftp_host_syntax_is_shared_by_central_and_agent_discovery_and_private_ips_remain_guarded(): void
    {
        $guard = new class extends \App\Services\Security\OutboundHostGuard
        {
            protected function resolveIps(string $host): array
            {
                return $host === 'sftp_backup' ? ['93.184.216.34'] : parent::resolveIps($host);
            }
        };
        $sftp = \Mockery::mock(\phpseclib3\Net\SFTP::class);
        $sftp->shouldReceive('getServerPublicHostKey')->andReturn('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIP7m24OrCqk9z3+lIB2Pa3L7Z5FkAOsXr7iKWvMkElYr');
        $storage = \Mockery::mock(DestinationStorage::class, [app(\App\Services\S3\S3ClientFactory::class), $guard])->makePartial()->shouldAllowMockingProtectedMethods();
        $storage->shouldReceive('newSftp')->times(3)->andReturn($sftp);
        $this->app->instance(DestinationStorage::class, $storage);
        $this->app->instance(\App\Services\Security\OutboundHostGuard::class, $guard);
        $this->actingAs(User::factory()->admin()->create());
        $host = $this->host();
        config(['volumevault.ssrf.allowed_ips' => ['127.0.0.1/32', '::1/128']]);
        foreach (['127.0.0.1', '::1', 'sftp_backup'] as $endpoint) {
            $this->postJson('/destinations/host-key', ['host' => $endpoint])->assertOk();
            $operation = app(DestinationOperations::class)->createHostKey($endpoint, 22, $host->id);
            $envelope = ['id' => $operation->id, 'token' => str_repeat('a', 64), 'kind' => 'destination', 'spec' => $operation->payload];
            app(AgentOperationSpecification::class)->validate($envelope);
            app(AgentOperationSpecification::class)->validateLocalPolicy($envelope);
        }
        config(['volumevault.ssrf.allowed_ips' => []]);
        foreach (['127.0.0.1', '::1'] as $endpoint) {
            $this->postJson('/destinations/host-key', ['host' => $endpoint])->assertUnprocessable();
            $operation = app(DestinationOperations::class)->createHostKey($endpoint, 22, $host->id);
            try {
                app(AgentOperationSpecification::class)->validateLocalPolicy(['kind' => 'destination', 'spec' => $operation->payload]);
                $this->fail('Private address bypassed agent policy.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('local policy', $exception->getMessage());
            }
        }
        foreach (['https://sftp.test', 'user@sftp.test', 'sftp.test/path', 'bad::address', '[::1]', "sftp\x00name", "sftp\nname"] as $endpoint) {
            $this->assertFalse(\App\Services\BackupDestinations\SftpEndpointHost::isValid($endpoint));
            $this->postJson('/destinations/host-key', ['host' => $endpoint])->assertUnprocessable();
            $this->postJson('/destinations/host-key', ['host' => $endpoint, 'docker_host_id' => $host->id])->assertUnprocessable();
        }
    }

    public static function hostBoundRequestCases(): array
    {
        return [['local', true], ['local', false], ['docker_volume', true], ['docker_volume', false]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostBoundRequestCases')]
    public function test_explicit_host_bound_measurement_owner_does_not_require_destination_capability(string $provider, bool $api): void
    {
        $host = $this->host();
        $host->forceFill(['agent_capabilities' => ['inventory-v1', 'backup-v1'], 'agent_host_path_allowlist' => ['/srv']])->save();
        $admin = User::factory()->admin()->create();
        if ($api) {
            Sanctum::actingAs($admin, ['read', 'write']);
        } else {
            $this->actingAs($admin);
        }
        $path = $api ? '/api/v1/destinations' : '/destinations';
        $data = ['name' => 'Owner archive', 'provider' => $provider, 'docker_host_id' => $host->id, 'storage_measurement_host_id' => $host->id,
            'settings' => $provider === 'local' ? ['archive_path' => '/srv/archives'] : ['volume_name' => 'archives'], 'is_active' => true];
        $response = $this->postJson($path, $data);
        $api ? $response->assertCreated() : $response->assertRedirect();
        $destination = BackupDestination::where('name', 'Owner archive')->sole();
        $this->assertSame($host->id, $destination->storage_measurement_host_id);
        $response = $this->putJson($path.'/'.$destination->id, [...$data, 'name' => 'Edited archive']);
        $api ? $response->assertOk() : $response->assertRedirect();
        $this->assertSame('Edited archive', $destination->fresh()->name);
        $this->putJson($path.'/'.$destination->id, [...$data, 'storage_measurement_host_id' => $this->host()->id])->assertUnprocessable()->assertJsonValidationErrors('storage_measurement_host_id');
    }

    public function test_generated_openapi_matches_host_scope_policy_and_discovery_capabilities(): void
    {
        $schema = $this->getJson('/api/v1/openapi.json')->assertOk()->json();
        $stacks = $schema['paths']['/stacks']['get']['summary'];
        $this->assertStringNotContainsString('unsupported', $stacks);
        $this->assertStringContainsString('backup-v1', $stacks);
        $policy = $schema['paths']['/host-path-allowlist']['get'];
        $this->assertSame('docker_host_id', $policy['parameters'][0]['name']);
        $this->assertSame(1, $policy['parameters'][0]['schema']['default']);
        $fields = $policy['responses'][200]['content']['application/json']['schema']['properties']['data'];
        $this->assertSame(['known', 'policy_unknown', 'local_disabled'], $fields['properties']['policy_status']['enum']);
        Sanctum::actingAs(User::factory()->admin()->create(), ['read']);
        $data = $this->getJson('/api/v1/host-path-allowlist')->assertOk()->assertJsonPath('data.policy_status', 'local_disabled')->json('data');
        $this->assertEqualsCanonicalizing(array_keys($data), $fields['required']);
        $host = $this->host();
        $host->forceFill(['last_seen_at' => now(), 'last_inventory_at' => now(), 'agent_host_path_allowlist' => []])->save();
        $this->getJson('/api/v1/host-path-allowlist?docker_host_id='.$host->id)->assertOk()->assertJsonPath('data.policy_status', 'known')->assertJsonPath('data.configured', false);
        $host->forceFill(['last_inventory_at' => now()->subHour()])->save();
        $this->getJson('/api/v1/host-path-allowlist?docker_host_id='.$host->id)->assertOk()->assertJsonPath('data.policy_status', 'policy_unknown')->assertJsonPath('data.configured', null)->assertJsonPath('data.freshness', 'stale');
        foreach (['destination-v1', 'sftp-host-key-v1'] as $capability) {
            $this->assertStringContainsString($capability, $schema['paths']['/destinations/host-key']['post']['summary']);
            $this->assertStringContainsString($capability, $schema['components']['schemas']['HostKeyRequest']['properties']['docker_host_id']['description']);
        }
        $host->forceFill(['agent_capabilities' => ['inventory-v1', 'sftp-host-key-v1']])->save();
        $option = collect(app(DestinationOperations::class)->hostOptions())->firstWhere('id', $host->id);
        $this->assertFalse($option['supports_sftp_host_key']);
    }
}
