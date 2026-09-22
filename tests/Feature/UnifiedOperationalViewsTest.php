<?php

namespace Tests\Feature;

use App\Actions\Docker\SyncDockerVolumes;
use App\Jobs\SyncDockerVolumesJob;
use App\Models\BackupDestination;
use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Models\DockerVolume;
use App\Models\RestoreRun;
use App\Models\User;
use App\Services\Docker\DockerProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UnifiedOperationalViewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_hosts_and_selected_host_use_composite_volume_and_stack_identities(): void
    {
        [$first, $second, $job] = $this->inventory();
        $user = User::factory()->user()->create();
        $this->actingAs($user)->get('/volumes')->assertInertia(fn (Assert $page) => $page->has('volumes', 3)->has('hosts', 3)->where('filters.docker_host_id', null));
        $this->get('/stacks')->assertInertia(fn (Assert $page) => $page->has('stacks', 3)->where('destinations', []));
        $token = $user->createToken('read', ['read'])->plainTextToken;
        $response = $this->withToken($token)->getJson('/api/v1/volumes')->assertOk()->assertJsonCount(3, 'data');
        $volumes = collect($response->json('data'))->keyBy('docker_host_id');
        $this->assertSame('backed_up', $volumes[$first->id]['backup_state']);
        $this->assertSame('unprotected', $volumes[$second->id]['backup_state']);
        $this->assertSame('unprotected', $volumes[1]['backup_state']);
        $this->assertStringContainsString('docker_host_id='.$first->id, $volumes[$first->id]['create_job_url']);
        $this->assertStringContainsString('volume=data', $volumes[$first->id]['create_job_url']);
        $this->assertSame(3, collect($response->json('data'))->pluck('identity')->unique()->count());
        $this->assertStringNotContainsString('super-secret', $response->getContent());
        $this->assertStringNotContainsString('agent_token_hash', $response->getContent());
        $this->assertFalse($volumes[$first->id]['canBackup']);
        $this->withToken($token)->getJson('/api/v1/stacks?docker_host_id='.$first->id)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.canBackup', false)
            ->assertJsonPath('data.0.backup_unavailable_reason', 'remote_stack_backup_unsupported');
        $this->withToken($token)->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.stats.total_volumes', 3)->assertJsonPath('data.stats.backed_up_volumes', 1);
        $this->withToken($token)->getJson('/api/v1/dashboard?docker_host_id='.$second->id)->assertOk()
            ->assertJsonPath('data.stats.total_volumes', 1)->assertJsonPath('data.stats.total_jobs', 0)
            ->assertJsonPath('data.stats.backed_up_volumes', 0)->assertJsonCount(0, 'data.recent_backup_runs');
        $this->withToken($token)->getJson('/api/v1/backup-jobs?docker_host_id='.$first->id)->assertOk()->assertJsonPath('data.0.id', $job->id);
    }

    public function test_orchestrator_reads_snapshots_and_disables_only_local_execution(): void
    {
        [$host] = $this->inventory();
        config(['volumevault.mode' => 'orchestrator']);
        $user = User::factory()->admin()->create();
        $token = $user->createToken('write', ['read', 'write'])->plainTextToken;
        $response = $this->withToken($token)->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.stats.total_volumes', 3);
        $hosts = collect($response->json('data.hosts'))->keyBy('id');
        $this->assertFalse($hosts[1]['canSync']);
        $this->assertFalse($hosts[1]['canBackup']);
        $this->assertSame('local_execution_disabled', $hosts[1]['availability']);
        $this->assertTrue($hosts[$host->id]['canBackup']);
        $this->assertFalse($hosts[$host->id]['canSync']);
    }

    public function test_run_filters_use_historical_execution_and_restore_target_hosts(): void
    {
        [$source, $target, $job] = $this->inventory();
        $job->update(['docker_host_id' => $target->id]);
        RestoreRun::create(['backup_job_id' => $job->id, 'source_docker_host_id' => $source->id, 'target_docker_host_id' => $target->id, 'mode' => 'new_volume', 'status' => 'queued', 'selected_backup_key' => 'backup.tar.gz', 'source_volume_name' => 'data', 'target_volume_name' => 'restored']);
        $user = User::factory()->user()->create();
        $token = $user->createToken('read', ['read'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/backup-runs?docker_host_id='.$source->id)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.docker_host.id', $source->id)->assertJsonPath('data.0.source_name', 'data');
        $this->withToken($token)->getJson('/api/v1/backup-runs?docker_host_id='.$target->id)->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($token)->getJson('/api/v1/restore-runs?docker_host_id='.$source->id)->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($token)->getJson('/api/v1/restore-runs?docker_host_id='.$target->id)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.source_docker_host.id', $source->id)->assertJsonPath('data.0.target_docker_host.id', $target->id);
    }

    public function test_invalid_scopes_and_remote_local_actions_are_rejected(): void
    {
        [$host] = $this->inventory();
        $user = User::factory()->admin()->create();
        $token = $user->createToken('write', ['read', 'write'])->plainTextToken;
        foreach (['dashboard', 'volumes', 'stacks', 'backup-jobs', 'backup-runs', 'restore-runs'] as $endpoint) {
            foreach (['all', '999999', '1.5'] as $invalid) {
                $this->withToken($token)->getJson('/api/v1/'.$endpoint.'?docker_host_id='.$invalid)->assertUnprocessable()->assertJsonValidationErrors('docker_host_id');
            }
        }
        Queue::fake();
        $this->withToken($token)->postJson('/api/v1/volumes/sync', ['docker_host_id' => $host->id])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/stacks/backup', ['docker_host_id' => $host->id, 'stack' => 'app'])->assertUnprocessable();
        Queue::assertNothingPushed();
        $this->withToken($token)->postJson('/api/v1/volumes/sync', ['async' => true])->assertUnprocessable()->assertJsonValidationErrors('docker_host_id');
        $this->withToken($token)->postJson('/api/v1/volumes/sync', ['docker_host_id' => 1, 'async' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('async');
        $this->withToken($token)->postJson('/api/v1/volumes/sync', ['docker_host_id' => 1, 'async' => true])->assertAccepted()->assertJsonPath('data.queued', true);
        Queue::assertPushed(SyncDockerVolumesJob::class);
    }

    public function test_group_history_is_scoped_by_member_run_hosts_instead_of_current_membership(): void
    {
        [$source, $target, $job] = $this->inventory();
        $group = BackupJobGroup::create(['name' => 'Group', 'schedule_type' => 'daily', 'schedule_config' => ['time' => '02:00'], 'cron_expression' => '0 2 * * *', 'status' => 'active', 'failure_policy' => 'continue']);
        $job->update(['backup_job_group_id' => $group->id, 'docker_host_id' => $target->id]);
        $groupRun = BackupGroupRun::create(['backup_job_group_id' => $group->id, 'status' => 'success', 'trigger' => 'manual', 'finished_at' => now(), 'total_members' => 1, 'succeeded_members' => 1]);
        BackupRun::firstOrFail()->update(['backup_group_run_id' => $groupRun->id]);
        $user = User::factory()->user()->create();
        $token = $user->createToken('read', ['read'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/dashboard?docker_host_id='.$source->id)->assertOk()
            ->assertJsonPath('data.stats.total_groups', 0)->assertJsonPath('data.stats.last_successful_group_backup_size', 1024)
            ->assertJsonCount(1, 'data.recent_group_runs')->assertJsonCount(0, 'data.recent_backup_runs');
        $this->withToken($token)->getJson('/api/v1/dashboard?docker_host_id='.$target->id)->assertOk()
            ->assertJsonPath('data.stats.total_groups', 1)->assertJsonPath('data.stats.last_successful_group_backup_size', null)
            ->assertJsonCount(0, 'data.recent_group_runs');
    }

    public function test_job_pagination_keeps_host_search_sort_and_status_scope(): void
    {
        [$host, $other, $job] = $this->inventory();
        for ($index = 0; $index < 12; $index++) {
            $copy = $job->replicate();
            $copy->name = sprintf('Scoped %02d', $index);
            $copy->volume_name = 'data_'.$index;
            $copy->save();
        }
        $copy = $job->replicate();
        $copy->docker_host_id = $other->id;
        $copy->name = 'Scoped other host';
        $copy->save();
        $this->actingAs(User::factory()->user()->create())
            ->get('/backup-jobs?docker_host_id='.$host->id.'&search=Scoped&status=active&sort=name&direction=asc&per_page=10&page=2')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.docker_host_id', $host->id)
            ->where('jobs.meta.total', 12)->where('jobs.meta.current_page', 2)->has('jobs.data', 2)
            ->where('jobs.data.0.name', 'Scoped 10')->where('jobs.data.0.docker_host.id', $host->id));
    }

    public function test_maintenance_missing_volumes_and_read_tokens_disable_actions(): void
    {
        [$host] = $this->inventory();
        $user = User::factory()->admin()->create();
        $token = $user->createToken('write', ['read', 'write'])->plainTextToken;
        $host->forceFill(['maintenance_requested_at' => now()])->save();
        $this->withToken($token)->getJson('/api/v1/volumes?docker_host_id='.$host->id)->assertOk()
            ->assertJsonPath('data.0.canBackup', false)->assertJsonPath('data.0.backup_unavailable_reason', 'maintenance');
        $host->forceFill(['maintenance_requested_at' => null])->save();
        DockerVolume::where('docker_host_id', $host->id)->update(['exists' => false]);
        $this->withToken($token)->getJson('/api/v1/volumes?docker_host_id='.$host->id)->assertOk()
            ->assertJsonPath('data.0.canBackup', false)->assertJsonPath('data.0.backup_unavailable_reason', 'volume_missing');
        $readToken = $user->createToken('read', ['read'])->plainTextToken;
        app('auth')->forgetGuards();
        $this->withToken($readToken)->getJson('/api/v1/volumes?docker_host_id=1')->assertOk()->assertJsonPath('data.0.canBackup', false)->assertJsonPath('data.0.canSync', false);
        DockerHost::findOrFail(1)->forceFill(['maintenance_requested_at' => now()])->save();
        Queue::fake();
        app('auth')->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/volumes/sync', ['docker_host_id' => 1])->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public function test_legacy_sync_returns_counts_and_web_async_refresh_is_explicit(): void
    {
        $user = User::factory()->admin()->create();
        $token = $user->createToken('write', ['read', 'write'])->plainTextToken;
        $counts = ['found' => 3, 'marked_missing' => 2, 'removed' => 1];
        $this->mock(SyncDockerVolumes::class)->shouldReceive('handle')->times(3)->andReturn($counts);
        Queue::fake();
        $this->withToken($token)->postJson('/api/v1/volumes/sync')->assertOk()->assertExactJson(['data' => $counts]);
        $this->withToken($token)->postJson('/api/v1/volumes/sync', ['docker_host_id' => 1, 'async' => false])->assertOk()->assertExactJson(['data' => $counts]);
        $this->actingAs($user)->post('/volumes/sync')->assertSessionHas('success', 'Synced 3 Docker volumes. 2 marked missing. 1 removed.');
        Queue::assertNothingPushed();
        $this->post('/volumes/sync', ['docker_host_id' => 1, 'async' => true])->assertSessionHas('success', 'Local Docker volume sync queued.');
        Queue::assertPushed(SyncDockerVolumesJob::class, 1);
        $this->getJson('/api/v1/openapi.json')->assertOk()
            ->assertJsonPath('paths./volumes/sync.post.requestBody.required', false)
            ->assertJsonStructure(['paths' => ['/volumes/sync' => ['post' => ['responses' => ['200', '202']]]]]);
    }

    public function test_offline_agents_remain_eligible_for_backup_queue_admission(): void
    {
        [$host] = $this->inventory();
        $host->forceFill(['last_seen_at' => now()->subMinutes(5)])->save();
        $user = User::factory()->admin()->create();
        $token = $user->createToken('write', ['read', 'write'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/volumes?docker_host_id='.$host->id)->assertOk()
            ->assertJsonPath('data.0.docker_host.status', 'offline')
            ->assertJsonPath('data.0.docker_host.availability', 'agent_offline')
            ->assertJsonPath('data.0.canBackup', true)
            ->assertJsonPath('data.0.backup_unavailable_reason', null);
    }

    public function test_next_schedule_serializes_cast_dates_as_exact_utc_instants_in_non_utc_timezone(): void
    {
        $timezone = date_default_timezone_get();
        config(['app.timezone' => 'Europe/Zurich']);
        date_default_timezone_set('Europe/Zurich');
        try {
            [$host, , $job] = $this->inventory();
            $job->update(['next_run_at' => '2026-10-01 08:15:00']);
            $group = BackupJobGroup::create(['name' => 'Scheduled group', 'schedule_type' => 'daily', 'schedule_config' => ['time' => '09:15'], 'cron_expression' => '15 9 * * *', 'status' => 'active', 'failure_policy' => 'continue', 'next_run_at' => '2026-10-01 09:15:00']);
            $member = $job->replicate();
            $member->backup_job_group_id = $group->id;
            $member->volume_name = 'member';
            $member->save();
            $user = User::factory()->user()->create();
            $this->actingAs($user)->get('/dashboard?docker_host_id='.$host->id)->assertInertia(fn (Assert $page) => $page->where('stats.next_scheduled_backup', '2026-10-01T06:15:00.000000Z'));
            $token = $user->createToken('read', ['read'])->plainTextToken;
            $this->withToken($token)->getJson('/api/v1/dashboard?docker_host_id='.$host->id)->assertOk()->assertJsonPath('data.stats.next_scheduled_backup', '2026-10-01T06:15:00.000000Z');
            $job->update(['next_run_at' => '2026-10-01 11:15:00']);
            $this->get('/dashboard?docker_host_id='.$host->id)->assertInertia(fn (Assert $page) => $page->where('stats.next_scheduled_backup', '2026-10-01T07:15:00.000000Z'));
            $this->getJson('/api/v1/dashboard?docker_host_id='.$host->id)->assertOk()->assertJsonPath('data.stats.next_scheduled_backup', '2026-10-01T07:15:00.000000Z');
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    private function inventory(): array
    {
        $this->mock(DockerProcess::class)->shouldNotReceive('run');
        $hosts = DockerHost::factory()->count(2)->create();
        foreach ($hosts as $host) {
            $host->forceFill(['agent_registered_at' => now(), 'last_seen_at' => now(), 'last_inventory_at' => now(), 'agent_protocol_version' => 1, 'agent_capabilities' => ['inventory-v1', 'backup-v1'], 'agent_token_hash' => 'super-secret', 'agent_enrollment_hash' => 'super-secret'])->save();
        }
        foreach ([1, ...$hosts->modelKeys()] as $hostId) {
            DockerVolume::create(['docker_host_id' => $hostId, 'name' => 'data', 'exists' => true, 'labels' => ['com.docker.compose.project' => 'app']]);
        }
        $destination = BackupDestination::create(['name' => 'Backups', 'provider' => 'local', 'bucket' => 'local', 'access_key_id' => '', 'secret_access_key' => '', 'settings' => ['archive_path' => '/tmp/backups']]);
        $job = BackupJob::create(['docker_host_id' => $hosts[0]->id, 'name' => 'Backup data', 'volume_name' => 'data', 'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME, 'backup_destination_id' => $destination->id, 'schedule_type' => 'daily', 'schedule_config' => ['time' => '02:00'], 'cron_expression' => '0 2 * * *', 'status' => 'active']);
        BackupRun::create(['docker_host_id' => $hosts[0]->id, 'backup_job_id' => $job->id, 'status' => 'success', 'trigger' => 'manual', 'source_type_snapshot' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME, 'source_volume_name' => 'data', 'finished_at' => now(), 'backup_size_bytes' => 1024]);

        return [$hosts[0], $hosts[1], $job];
    }
}
