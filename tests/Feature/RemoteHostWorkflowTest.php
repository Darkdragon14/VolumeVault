<?php

namespace Tests\Feature;

use App\Actions\Backup\CreateBackupGroupRun;
use App\Actions\Backup\CreateBackupRun;
use App\Actions\Backup\CreateBackupRunRecord;
use App\Actions\Docker\ValidateHostPathMount;
use App\Actions\Restore\CreateRestoreRun;
use App\Actions\Runs\DispatchQueuedRun;
use App\Jobs\DispatchDueBackupGroupsJob;
use App\Jobs\DispatchDueBackupJobsJob;
use App\Models\AgentOperation;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Models\DockerVolume;
use App\Models\RestoreRun;
use App\Models\User;
use App\Services\Agents\AgentOperationBroker;
use App\Services\BackupDestinations\ListBackupObjects;
use App\Services\Docker\DockerProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RemoteHostWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['volumevault.mode' => 'orchestrator']);
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->withToken($user->createToken('remote', ['read', 'write'])->plainTextToken);
        $this->mock(DockerProcess::class)->shouldNotReceive('run');
        $this->mock(ValidateHostPathMount::class)->shouldNotReceive('handle');
    }

    public function test_api_creates_and_updates_offline_remote_job_and_preserves_omitted_host(): void
    {
        $host = $this->host();
        $data = $this->payload($host);
        $response = $this->postJson('/api/v1/backup-jobs', $data)->assertCreated()->assertJsonPath('data.docker_host_id', $host->id);
        $id = $response->json('data.id');
        unset($data['docker_host_id']);
        $this->putJson("/api/v1/backup-jobs/{$id}", [...$data, 'name' => 'Edited offline'])
            ->assertOk()->assertJsonPath('data.docker_host_id', $host->id);
        $job = BackupJob::findOrFail($id);
        $run = app(CreateBackupRun::class)->handle($job, BackupRun::TRIGGER_MANUAL);
        $this->assertSame($host->id, $run->docker_host_id);
        $this->assertSame('app_data', $run->source_volume_name);
        $this->assertSame(BackupRun::STATUS_QUEUED, $run->status);
        $this->assertNull($run->started_at);
    }

    public function test_remote_job_can_be_resumed_in_orchestrator_mode(): void
    {
        $host = $this->host();
        $id = $this->postJson('/api/v1/backup-jobs', $this->payload($host))->assertCreated()->json('data.id');
        $job = BackupJob::findOrFail($id);
        $job->update(['status' => BackupJob::STATUS_ERROR, 'last_error' => 'previous failure']);
        $this->postJson('/api/v1/backup-jobs/'.$id.'/resume')->assertOk();
        $this->assertSame(BackupJob::STATUS_ACTIVE, $job->refresh()->status);
        $this->assertSame($host->id, $job->docker_host_id);
    }

    public function test_inventory_destination_and_required_group_membership_are_validated(): void
    {
        $a = $this->host();
        $b = $this->host();
        $data = $this->payload($a);
        $this->postJson('/api/v1/backup-jobs', [...$data, 'docker_host_id' => $b->id])->assertUnprocessable()->assertJsonValidationErrors('volume_name');
        $local = $this->localDestination($b);
        $this->postJson('/api/v1/backup-jobs', [...$data, 'backup_destination_id' => $local->id])->assertUnprocessable()->assertJsonValidationErrors('backup_destination_id');
        $this->postJson('/api/v1/backup-jobs', [...$data, 'planning_mode' => 'group'])->assertUnprocessable()->assertJsonValidationErrors('backup_job_group_id');
        $this->assertDatabaseCount('backup_jobs', 0);
    }

    public function test_orchestrator_group_crud_membership_schedule_and_safe_host_identity(): void
    {
        Queue::fake();
        $settings = ['name' => 'Remote group', 'schedule_type' => 'daily', 'schedule_config' => ['time' => '02:00'], 'failure_policy' => 'continue', 'notifications_enabled' => false];
        $id = $this->postJson('/api/v1/backup-groups', $settings)->assertCreated()->json('data.id');
        $host = $this->host();
        $payload = [...$this->payload($host), 'planning_mode' => 'group', 'backup_job_group_id' => $id];
        $jobId = $this->postJson('/api/v1/backup-jobs', $payload)->assertCreated()->json('data.id');
        $this->putJson('/api/v1/backup-groups/'.$id, [...$settings, 'name' => 'Edited'])->assertOk();
        $response = $this->getJson('/api/v1/backup-groups/'.$id)->assertOk()
            ->assertJsonPath('data.members.0.docker_host_id', $host->id)
            ->assertJsonPath('data.members.0.docker_host.id', $host->id)
            ->assertJsonPath('data.members.0.docker_host.is_local', false);
        $this->assertArrayNotHasKey('agent_token_hash', $response->json('data.members.0.docker_host'));
        $this->postJson('/api/v1/backup-groups/'.$id.'/pause')->assertOk();
        $this->postJson('/api/v1/backup-groups/'.$id.'/resume')->assertOk();
        $group = BackupJobGroup::findOrFail($id);
        $group->update(['next_run_at' => now()->subMinute()]);
        app(DispatchDueBackupGroupsJob::class)->handle(app(CreateBackupGroupRun::class), app(DispatchQueuedRun::class));
        $run = $group->groupRuns()->firstOrFail();
        $this->assertCount(1, $run->member_run_ids);
        $this->assertSame('running', $run->status);
        $this->assertSame(1, AgentOperation::count());
        $this->getJson('/api/v1/backup-group-runs/'.$run->id)->assertOk()
            ->assertJsonPath('data.members.0.docker_host_id', $host->id)
            ->assertJsonPath('data.members.0.docker_host.name', $host->name);
        $this->putJson('/api/v1/backup-groups/'.$id, [...$settings, 'failure_policy' => 'stop'])->assertOk();
        $this->assertSame('continue', $run->fresh()->failure_policy_snapshot);
        $this->postJson('/api/v1/backup-groups/'.$id.'/pause')->assertUnprocessable();
        $this->deleteJson('/api/v1/backup-groups/'.$id)->assertUnprocessable();
        $this->putJson('/api/v1/backup-jobs/'.$jobId, [...$payload, 'planning_mode' => 'standalone', 'backup_job_group_id' => null])->assertUnprocessable();
    }

    public function test_local_defaults_work_in_hybrid_but_are_rejected_in_orchestrator(): void
    {
        $data = $this->payload(DockerHost::findOrFail(DockerHost::LOCAL_ID));
        unset($data['docker_host_id']);
        $this->postJson('/api/v1/backup-jobs', $data)->assertUnprocessable();
        config(['volumevault.mode' => 'hybrid']);
        $this->postJson('/api/v1/backup-jobs', $data)->assertCreated()->assertJsonPath('data.docker_host_id', DockerHost::LOCAL_ID);
    }

    public function test_registration_capability_revocation_and_maintenance_gate_new_work(): void
    {
        $host = $this->host();
        $data = $this->payload($host);
        foreach ([['agent_registered_at' => null], ['agent_revoked_at' => now()], ['agent_protocol_version' => 2], ['agent_capabilities' => ['inventory-v1', 'restore-v1']], ['maintenance_requested_at' => now()]] as $invalid) {
            $host->forceFill(['agent_registered_at' => now(), 'agent_revoked_at' => null, 'agent_protocol_version' => 1, 'agent_capabilities' => ['inventory-v1', 'backup-v1', 'restore-v1'], 'maintenance_requested_at' => null, ...$invalid])->save();
            $this->postJson('/api/v1/backup-jobs', $data)->assertUnprocessable()->assertJsonValidationErrors('docker_host_id');
        }
        $this->assertDatabaseCount('backup_jobs', 0);
    }

    public function test_host_change_is_blocked_by_outstanding_run(): void
    {
        $a = $this->host();
        $b = $this->host();
        $data = $this->payload($a);
        DockerVolume::create(['docker_host_id' => $b->id, 'name' => 'app_data', 'exists' => true]);
        $id = $this->postJson('/api/v1/backup-jobs', $data)->assertCreated()->json('data.id');
        app(CreateBackupRun::class)->handle(BackupJob::findOrFail($id), BackupRun::TRIGGER_MANUAL);
        $this->putJson("/api/v1/backup-jobs/{$id}", [...$data, 'docker_host_id' => $b->id])->assertUnprocessable()->assertJsonValidationErrors('source_type');
        $this->assertSame($a->id, BackupJob::findOrFail($id)->docker_host_id);
    }

    public function test_remote_host_paths_use_agent_allowlist_without_central_mount_check(): void
    {
        $host = $this->host();
        $data = [...$this->payload($host), 'source_type' => BackupJob::SOURCE_TYPE_HOST_PATH, 'host_path' => '/srv/remote/data', 'stop_containers_before_backup' => true, 'stop_container_names' => ['app']];
        $this->postJson('/api/v1/backup-jobs', $data)->assertCreated();
        foreach (['/srv/remote-other', '/srv/remote/../etc', '/etc'] as $path) {
            $this->postJson('/api/v1/backup-jobs', [...$data, 'host_path' => $path])->assertUnprocessable()->assertJsonValidationErrors('host_path');
        }
        $this->postJson('/api/v1/backup-jobs', [...$data, 'stop_container_names' => ['foreign']])->assertUnprocessable()->assertJsonValidationErrors('stop_container_names');
    }

    public function test_remote_local_destination_and_network_secrets_round_trip_safely(): void
    {
        $host = $this->host();
        $data = ['name' => 'Remote archive', 'provider' => 'local', 'docker_host_id' => $host->id, 'settings' => ['archive_path' => '/srv/remote/backups']];
        $id = $this->postJson('/api/v1/destinations', $data)->assertCreated()->assertJsonPath('data.docker_host_id', $host->id)->json('data.id');
        unset($data['docker_host_id']);
        $this->putJson("/api/v1/destinations/{$id}", $data)->assertOk()->assertJsonPath('data.docker_host_id', $host->id);
        $this->postJson('/api/v1/destinations', [...$data, 'docker_host_id' => $host->id, 'settings' => ['archive_path' => '/etc']])->assertUnprocessable();
        $response = $this->postJson('/api/v1/destinations', ['name' => 'S3', 'provider' => 'aws_s3', 'bucket' => 'archives', 'access_key_id' => 'private-access', 'secret_access_key' => 'private-secret'])->assertCreated();
        $destination = BackupDestination::findOrFail($response->json('data.id'));
        $this->assertStringNotContainsString('private-secret', $destination->getRawOriginal('secret_access_key'));
        $this->assertStringNotContainsString('private-secret', $response->getContent());
        $this->get("/destinations/{$destination->id}/edit")->assertOk()->assertDontSee('private-secret');
    }

    public function test_new_volume_cross_host_restore_uses_historical_source_and_allows_same_name(): void
    {
        $a = $this->host();
        $b = $this->host();
        $job = $this->job($a);
        $backup = $this->successfulBackup($job);
        $job->update(['docker_host_id' => $b->id, 'volume_name' => 'different']);
        $this->mock(ListBackupObjects::class)->shouldReceive('contains')->once()->withArgs(fn ($destination, $key, $exhaustive): bool => $key === $backup->backup_key && $exhaustive)->andReturnTrue();
        $this->mock(DispatchQueuedRun::class)->shouldReceive('handle')->once()->andReturnFalse();
        $this->postJson("/api/v1/backup-jobs/{$job->id}/restore", [
            'backup_run_id' => $backup->id, 'selected_backup_key' => $backup->backup_key,
            'mode' => RestoreRun::MODE_NEW_VOLUME, 'target_docker_host_id' => $b->id, 'target_volume_name' => 'app_data',
        ])->assertAccepted()->assertJsonPath('data.source_docker_host_id', $a->id)->assertJsonPath('data.target_docker_host_id', $b->id)->assertJsonPath('data.source_volume_name', 'app_data');
        $this->assertDatabaseMissing('docker_volumes', ['docker_host_id' => $b->id, 'name' => 'app_data']);
    }

    public function test_historical_volume_restore_remains_assignable_after_job_changes_to_host_path(): void
    {
        $host = $this->host();
        $host->forceFill(['agent_token_hash' => hash('sha256', 'test-agent'), 'agent_instance_id' => (string) Str::uuid(), 'last_seen_at' => now()])->save();
        $job = $this->job($host);
        $backup = $this->successfulBackup($job);
        $job->update(['source_type' => BackupJob::SOURCE_TYPE_HOST_PATH, 'volume_name' => null, 'host_path' => '/srv/remote/new-source']);
        $this->mock(ListBackupObjects::class)->shouldReceive('contains')->once()->andReturnTrue();
        $run = app(CreateRestoreRun::class)->handle($job, [
            'backup_run_id' => $backup->id, 'selected_backup_key' => $backup->backup_key,
            'mode' => RestoreRun::MODE_INPLACE, 'target_docker_host_id' => $host->id,
            'confirmation_text' => 'app_data', 'backup_before_overwrite' => false,
        ]);
        app(DispatchQueuedRun::class)->handle($run);
        $operation = app(AgentOperationBroker::class)->pull($host->fresh());
        $this->assertNotNull($operation);
        $this->assertSame(BackupJob::SOURCE_TYPE_DOCKER_VOLUME, $operation['spec']['job']['source_type']);
        $this->assertSame('app_data', $operation['spec']['job']['volume_name']);
        $this->assertNull($operation['spec']['job']['host_path']);
        $this->assertSame('app_data', $operation['spec']['run']['source_volume_name']);
        $this->assertSame('app_data', $operation['spec']['run']['target_volume_name']);
        $this->assertSame(RestoreRun::STATUS_RUNNING, $run->refresh()->status);
        $this->assertSame(BackupJob::SOURCE_TYPE_HOST_PATH, $job->refresh()->source_type);
    }

    public function test_historical_host_path_backup_still_cannot_be_restored_in_place_after_job_changes(): void
    {
        $host = $this->host();
        $job = $this->job($host);
        $job->update(['source_type' => BackupJob::SOURCE_TYPE_HOST_PATH, 'volume_name' => null, 'host_path' => '/srv/remote/old-source']);
        $backup = $this->successfulBackup($job);
        $job->update(['source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME, 'volume_name' => 'app_data', 'host_path' => null]);
        $this->mock(ListBackupObjects::class)->shouldReceive('contains')->andReturnTrue();

        $this->postJson('/api/v1/backup-jobs/'.$job->id.'/restore', [
            'backup_run_id' => $backup->id, 'selected_backup_key' => $backup->backup_key,
            'mode' => RestoreRun::MODE_INPLACE, 'target_docker_host_id' => $host->id,
            'confirmation_text' => 'app_data', 'backup_before_overwrite' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('mode');
        $this->assertDatabaseCount('restore_runs', 0);
    }

    public function test_restore_defaults_to_job_host_and_remote_archive_requires_matching_run_and_owner(): void
    {
        $a = $this->host();
        $b = $this->host();
        $job = $this->job($a, $this->localDestination($a));
        $backup = $this->successfulBackup($job);
        $this->mock(ListBackupObjects::class)->shouldNotReceive('contains');
        $this->mock(DispatchQueuedRun::class)->shouldReceive('handle')->once()->andReturnFalse();
        $data = ['backup_run_id' => $backup->id, 'selected_backup_key' => $backup->backup_key, 'mode' => RestoreRun::MODE_NEW_VOLUME, 'target_volume_name' => 'restored'];
        $this->postJson("/api/v1/backup-jobs/{$job->id}/restore", $data)->assertAccepted()->assertJsonPath('data.target_docker_host_id', $a->id);
        $this->postJson("/api/v1/backup-jobs/{$job->id}/restore", [...$data, 'target_docker_host_id' => $b->id])->assertUnprocessable()->assertJsonValidationErrors('target_docker_host_id');
        $this->postJson("/api/v1/backup-jobs/{$job->id}/restore", [...$data, 'selected_backup_key' => 'foreign.tar.gz'])->assertUnprocessable()->assertJsonValidationErrors('selected_backup_key');
        $this->postJson("/api/v1/backup-jobs/{$job->id}/restore", [...$data, 'backup_run_id' => null])->assertUnprocessable()->assertJsonValidationErrors('backup_run_id');
        $this->getJson("/api/v1/backup-jobs/{$job->id}/backups")->assertOk()->assertJsonPath('data.0.backup_run_id', $backup->id)->assertJsonPath('data.0.verification_deferred', true);
    }

    public function test_in_place_requires_target_inventory_confirmation_and_target_applicable_safety_backup(): void
    {
        $a = $this->host();
        $b = $this->host();
        $job = $this->job($a);
        $backup = $this->successfulBackup($job);
        $this->mock(ListBackupObjects::class)->shouldReceive('contains')->andReturnTrue();
        $data = ['backup_run_id' => $backup->id, 'selected_backup_key' => $backup->backup_key, 'mode' => RestoreRun::MODE_INPLACE, 'target_docker_host_id' => $b->id, 'confirmation_text' => 'app_data'];
        $url = "/api/v1/backup-jobs/{$job->id}/restore";
        $this->postJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('mode');
        DockerVolume::create(['docker_host_id' => $b->id, 'name' => 'app_data', 'exists' => true]);
        $this->postJson($url, [...$data, 'confirmation_text' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('confirmation_text');
        $this->postJson($url, [...$data, 'backup_before_overwrite' => true])->assertUnprocessable()->assertJsonValidationErrors('backup_before_overwrite');
        $this->mock(DispatchQueuedRun::class)->shouldReceive('handle')->once()->andReturnFalse();
        $this->postJson($url, $data)->assertAccepted()->assertJsonPath('data.target_docker_host_id', $b->id);
    }

    public function test_restore_target_maintenance_and_local_mode_are_enforced_independently_of_source(): void
    {
        $a = $this->host();
        $b = $this->host();
        $job = $this->job($a);
        $data = ['selected_backup_key' => 'backup.tar.gz', 'mode' => RestoreRun::MODE_NEW_VOLUME, 'target_volume_name' => 'restored'];
        $b->forceFill(['maintenance_requested_at' => now()])->save();
        $this->postJson("/api/v1/backup-jobs/{$job->id}/restore", [...$data, 'target_docker_host_id' => $b->id])->assertUnprocessable();
        $this->postJson("/api/v1/backup-jobs/{$job->id}/restore", [...$data, 'target_docker_host_id' => DockerHost::LOCAL_ID])->assertUnprocessable();
        $this->assertDatabaseCount('restore_runs', 0);
    }

    public function test_restore_checks_target_capability_and_admission_instead_of_archive_source(): void
    {
        $a = $this->host();
        $b = $this->host();
        $job = $this->job($a);
        $backup = $this->successfulBackup($job);
        $a->forceFill(['maintenance_requested_at' => now(), 'agent_revoked_at' => now()])->save();
        $b->forceFill(['agent_capabilities' => ['inventory-v1', 'backup-v1']])->save();
        $data = ['backup_run_id' => $backup->id, 'selected_backup_key' => $backup->backup_key, 'target_docker_host_id' => $b->id, 'mode' => RestoreRun::MODE_NEW_VOLUME];
        $this->postJson("/api/v1/backup-jobs/{$job->id}/restore", $data)->assertUnprocessable()->assertJsonValidationErrors('target_docker_host_id');
        $b->forceFill(['agent_capabilities' => ['inventory-v1', 'restore-v1']])->save();
        $this->mock(ListBackupObjects::class)->shouldReceive('contains')->once()->andReturnTrue();
        $this->mock(DispatchQueuedRun::class)->shouldReceive('handle')->once()->andReturnFalse();
        $this->postJson("/api/v1/backup-jobs/{$job->id}/restore", $data)->assertAccepted()
            ->assertJsonPath('data.source_docker_host_id', $a->id)->assertJsonPath('data.target_docker_host_id', $b->id);
    }

    public function test_forms_expose_host_owned_inventory_without_querying_local_docker(): void
    {
        $host = $this->host();
        $job = $this->job($host, $this->localDestination($host));
        $backup = $this->successfulBackup($job);
        $this->get('/backup-jobs/create')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('hosts.0.id', $host->id)->missing('hosts.0.agent_token_hash')
            ->where('volumes.0.docker_host_id', $host->id)->where('containers.0.docker_host_id', $host->id)
            ->where('destinations.0.docker_host_id', $host->id));
        $this->get("/backup-jobs/{$job->id}/restore?backup_run_id={$backup->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('sourceDockerHostId', $host->id)->where('targetDockerHostId', $host->id)
            ->where('backups.0.verification_deferred', true));
    }

    public function test_scheduler_and_dispatch_command_include_remote_jobs_in_orchestrator(): void
    {
        $host = $this->host();
        $remote = $this->job($host);
        $local = $this->job(DockerHost::findOrFail(DockerHost::LOCAL_ID));
        $dispatch = $this->mock(DispatchQueuedRun::class);
        $dispatch->shouldReceive('handle')->twice()->withArgs(fn (BackupRun $run): bool => $run->docker_host_id === $host->id)->andReturnFalse();
        app()->call([new DispatchDueBackupJobsJob, 'handle']);
        $this->artisan('volumevault:dispatch-queued-runs')->assertSuccessful();
        $this->assertSame(1, $remote->runs()->count());
        $this->assertSame(0, $local->runs()->count());
    }

    public function test_missing_remote_inventory_is_not_satisfied_by_a_local_homonym(): void
    {
        $host = $this->host();
        $data = $this->payload($host);
        DockerVolume::where('docker_host_id', $host->id)->update(['exists' => false]);
        DockerVolume::create(['docker_host_id' => DockerHost::LOCAL_ID, 'name' => 'app_data', 'exists' => true]);
        $this->postJson('/api/v1/backup-jobs', $data)->assertUnprocessable()->assertJsonValidationErrors('volume_name');
    }

    public function test_web_remote_job_mutations_share_api_guards_including_pending_cleanup(): void
    {
        $a = $this->host();
        $b = $this->host();
        $data = $this->payload($a);
        $this->post('/backup-jobs', $data)->assertSessionHasNoErrors()->assertRedirect();
        $job = BackupJob::firstOrFail();
        $run = app(CreateBackupRun::class)->handle($job, BackupRun::TRIGGER_MANUAL);
        $run->forceFill(['status' => BackupRun::STATUS_FAILED, 'docker_container_cleanup_pending' => true])->save();
        DockerVolume::create(['docker_host_id' => $b->id, 'name' => 'app_data', 'exists' => true]);
        $this->put("/backup-jobs/{$job->id}", [...$data, 'docker_host_id' => $b->id])->assertSessionHasErrors('source_type');
        $run->forceFill(['docker_container_cleanup_pending' => false])->save();
        $this->put("/backup-jobs/{$job->id}", [...$data, 'docker_host_id' => $b->id])->assertSessionHasNoErrors();
        $this->assertSame($b->id, $job->fresh()->docker_host_id);
    }

    public function test_network_archives_still_require_central_verification_and_target_must_be_new(): void
    {
        $a = $this->host();
        $b = $this->host();
        $job = $this->job($a);
        $backup = $this->successfulBackup($job);
        $data = ['backup_run_id' => $backup->id, 'selected_backup_key' => $backup->backup_key, 'target_docker_host_id' => $b->id, 'mode' => RestoreRun::MODE_NEW_VOLUME, 'target_volume_name' => 'restored'];
        $listing = $this->mock(ListBackupObjects::class);
        $listing->shouldReceive('contains')->once()->andReturnFalse();
        $this->postJson("/api/v1/backup-jobs/{$job->id}/restore", $data)->assertUnprocessable()->assertJsonValidationErrors('selected_backup_key');
        $listing->shouldReceive('contains')->once()->andReturnTrue();
        DockerVolume::create(['docker_host_id' => $b->id, 'name' => 'restored', 'exists' => true]);
        $this->postJson("/api/v1/backup-jobs/{$job->id}/restore", $data)->assertUnprocessable()->assertJsonValidationErrors('target_volume_name');
        $this->assertDatabaseCount('restore_runs', 0);
    }

    public function test_remote_archive_rejects_foreign_run_or_changed_locator_without_central_io(): void
    {
        $host = $this->host();
        $destination = $this->localDestination($host);
        $job = $this->job($host, $destination);
        $otherJob = $this->job($host, $destination);
        $backup = $this->successfulBackup($otherJob);
        $this->mock(ListBackupObjects::class)->shouldNotReceive('contains');
        $data = ['backup_run_id' => $backup->id, 'selected_backup_key' => $backup->backup_key, 'mode' => RestoreRun::MODE_NEW_VOLUME, 'target_volume_name' => 'restored'];
        $this->postJson("/api/v1/backup-jobs/{$job->id}/restore", $data)->assertUnprocessable()->assertJsonValidationErrors('backup_run_id');
        $destination->update(['settings' => ['archive_path' => '/srv/remote/moved']]);
        $this->postJson("/api/v1/backup-jobs/{$otherJob->id}/restore", $data)->assertUnprocessable()->assertJsonValidationErrors('destination');
        $this->assertDatabaseCount('restore_runs', 0);
    }

    private function host(): DockerHost
    {
        return DockerHost::factory()->create([
            'agent_registered_at' => now(), 'agent_protocol_version' => 1,
            'agent_capabilities' => ['inventory-v1', 'backup-v1', 'restore-v1'], 'last_seen_at' => now()->subDay(),
            'agent_host_path_allowlist' => ['/srv/remote/'], 'agent_containers' => [['names' => '/app', 'id' => str_repeat('a', 12)]],
        ]);
    }

    private function destination(): BackupDestination
    {
        return BackupDestination::create(['name' => 'Shared network', 'provider' => 'aws_s3', 'bucket' => 'backups', 'access_key_id' => 'secret-id', 'secret_access_key' => 'secret-key', 'is_active' => true]);
    }

    private function localDestination(DockerHost $host): BackupDestination
    {
        return BackupDestination::create(['name' => 'Agent archive', 'provider' => 'local', 'docker_host_id' => $host->id, 'bucket' => 'local', 'access_key_id' => '', 'secret_access_key' => '', 'settings' => ['archive_path' => '/srv/remote/backups'], 'is_active' => true]);
    }

    private function payload(DockerHost $host, ?BackupDestination $destination = null): array
    {
        DockerVolume::firstOrCreate(['docker_host_id' => $host->id, 'name' => 'app_data'], ['exists' => true]);

        return ['name' => 'App backup', 'docker_host_id' => $host->id, 'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME, 'volume_name' => 'app_data', 'backup_destination_id' => ($destination ?? $this->destination())->id, 'schedule_type' => BackupJob::SCHEDULE_DAILY, 'schedule_config' => ['time' => '02:00']];
    }

    private function job(DockerHost $host, ?BackupDestination $destination = null): BackupJob
    {
        return BackupJob::create([...$this->payload($host, $destination), 'cron_expression' => '0 2 * * *', 'status' => BackupJob::STATUS_ACTIVE, 'next_run_at' => now()->subDay()]);
    }

    private function successfulBackup(BackupJob $job): BackupRun
    {
        return app(CreateBackupRunRecord::class)->handle($job, ['status' => BackupRun::STATUS_SUCCESS, 'trigger' => BackupRun::TRIGGER_MANUAL, 'backup_key' => 'backup.tar.gz', 'finished_at' => now()]);
    }
}
