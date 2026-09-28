<?php

namespace Tests\Feature;

use App\Actions\Backup\ApplyPendingDockerLabelReconciliation;
use App\Actions\Destinations\DestinationMutationBlocked;
use App\Actions\Destinations\MutateDestination;
use App\Actions\Notifications\DeleteNotificationChannel;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Models\DockerLabelBackupSetting;
use App\Models\DockerVolume;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Services\Agents\AgentCompatibility;
use App\Services\Agents\AgentLabelInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RemoteDockerLabelBackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['volumevault.mode' => 'orchestrator', 'volumevault.agents.enabled' => true]);
        Process::preventStrayProcesses();
        $this->withServerVariables(['HTTPS' => 'on']);
    }

    public function test_complete_inventory_reconciles_identical_names_on_two_hosts_without_touching_local_settings(): void
    {
        $destination = $this->destination();
        $a = $this->host($destination);
        $b = $this->host($destination);
        $local = DockerLabelBackupSetting::current()->getAttributes();
        foreach ([$a, $b] as $host) {
            $this->sendInventory($host, $this->inventory($host))->assertOk();
        }
        $jobs = BackupJob::orderBy('docker_host_id')->get();
        $this->assertCount(2, $jobs);
        $this->assertSame($jobs[0]->configuration_key, $jobs[1]->configuration_key);
        $this->assertSame([$a->id, $b->id], $jobs->pluck('docker_host_id')->all());
        $this->assertSame($local, DockerLabelBackupSetting::current()->getAttributes());

        $this->sendInventory($a, $this->inventory($a, 2, false))->assertOk();
        $this->assertSame(BackupJob::STATUS_ERROR, $jobs[0]->refresh()->status);
        $this->assertSame(BackupJob::STATUS_ACTIVE, $jobs[1]->refresh()->status);
        $this->sendInventory($a, $this->inventory($a, 3))->assertOk();
        $this->assertSame(BackupJob::STATUS_ACTIVE, $jobs[0]->refresh()->status);
        $this->assertNull($jobs[0]->label_reconciliation_error);
        $this->sendInventory($a, $this->inventory($a, 2, false))->assertOk();
        $this->assertSame(BackupJob::STATUS_ACTIVE, $jobs[0]->refresh()->status);

        $missing = $this->inventory($a, 4);
        $missing['volumes'] = [];
        $this->sendInventory($a, $missing)->assertOk();
        $this->assertSame(BackupJob::STATUS_ERROR, $jobs[0]->refresh()->status);
        $this->assertTrue(DockerVolume::where('docker_host_id', $b->id)->firstOrFail()->exists);
        $this->sendInventory($a, $this->inventory($a, 5))->assertOk();
        $this->assertSame(BackupJob::STATUS_ACTIVE, $jobs[0]->refresh()->status);
    }

    public function test_old_partial_and_disabled_inventories_preserve_existing_jobs(): void
    {
        $host = $this->host($this->destination());
        $this->sendInventory($host, $this->inventory($host))->assertOk();
        $job = BackupJob::firstOrFail();
        $original = $job->getAttributes();
        $old = $this->inventory($host, 2, false);
        unset($old['label_inventory']);
        $this->sendInventory($host, $old)->assertOk();
        $this->assertSame($original, $job->refresh()->getAttributes());
        $this->assertStringContainsString('docker-labels-v1', DockerLabelBackupSetting::current($host->id)->last_sync_error);
        $partial = $this->inventory($host, 3, false);
        $partial['label_inventory']['complete'] = false;
        $this->sendInventory($host, $partial)->assertOk();
        $this->assertSame($original, $job->refresh()->getAttributes());
        $host->forceFill(['agent_capabilities' => ['inventory-v1']])->save();
        $this->sendInventory($host, $this->inventory($host, 4, false))->assertOk();
        $this->assertSame($original, $job->refresh()->getAttributes());
        DockerLabelBackupSetting::current($host->id)->update(['enabled' => false]);
        $settings = DockerLabelBackupSetting::current($host->id)->getAttributes();
        $this->sendInventory($host, $this->inventory($host, 5, false))->assertOk();
        $this->assertSame($settings, DockerLabelBackupSetting::current($host->id)->getAttributes());
        $this->assertSame($original, $job->refresh()->getAttributes());
    }

    public function test_foreign_destinations_and_volumes_cannot_be_selected_by_agent_labels(): void
    {
        $shared = $this->destination();
        $a = $this->host($shared);
        $b = $this->host($shared);
        $foreign = $this->destination('Foreign', $b->id);
        DockerVolume::create(['docker_host_id' => $b->id, 'name' => 'foreign-volume', 'exists' => true]);
        $data = $this->inventory($a);
        $data['label_inventory']['containers'][0]['labels']['dev.darkdragon14.volumevault.backup.destination'] = $foreign->name;
        $this->sendInventory($a, $data)->assertOk();
        $this->assertDatabaseCount('backup_jobs', 0);
        $this->assertNotNull(DockerLabelBackupSetting::current($a->id)->last_sync_error);
        $data = $this->inventory($a, 2);
        $data['label_inventory']['containers'][0]['mounts'][0]['name'] = 'foreign-volume';
        $this->sendInventory($a, $data)->assertOk();
        $this->assertDatabaseCount('backup_jobs', 0);

        $own = $this->destination('Foreign', $a->id);
        $data = $this->inventory($a, 3);
        $data['label_inventory']['containers'][0]['labels']['dev.darkdragon14.volumevault.backup.destination'] = 'Foreign';
        $this->sendInventory($a, $data)->assertOk();
        $this->assertSame($own->id, BackupJob::firstOrFail()->backup_destination_id);
    }

    public function test_malformed_and_incomplete_claims_are_rejected_without_replacing_inventory(): void
    {
        $host = $this->host($this->destination());
        $this->sendInventory($host, $this->inventory($host))->assertOk();
        $job = BackupJob::firstOrFail();
        $original = $job->getAttributes();
        foreach (['mounts', 'container', 'secret', 'host', 'oversized'] as $case) {
            $data = $this->inventory($host, 2);
            if ($case === 'mounts') {
                unset($data['label_inventory']['containers'][0]['mounts']);
            } elseif ($case === 'container') {
                $data['label_inventory']['containers'] = [];
            } elseif ($case === 'secret') {
                $data['label_inventory']['containers'][0]['labels']['DATABASE_PASSWORD'] = 'secret';
            } elseif ($case === 'host') {
                $data['label_inventory']['containers'][0]['mounts'][0]['source'] = '/etc';
            } else {
                $data['label_inventory']['containers'][0]['labels']['dev.darkdragon14.volumevault.backup.mount'] = str_repeat('x', 17000);
            }
            $this->sendInventory($host, $data)->assertUnprocessable();
            $this->assertSame(1, $host->refresh()->agent_inventory_sequence);
            $this->assertSame($original, $job->refresh()->getAttributes());
        }
    }

    public function test_container_identity_matching_requires_a_bijection(): void
    {
        $host = $this->host($this->destination());
        $this->sendInventory($host, $this->inventory($host))->assertOk();
        $job = BackupJob::firstOrFail();
        $original = $job->getAttributes();
        $data = $this->inventory($host, 2);
        $data['containers'] = [['id' => 'aaaaaaaaaaaa'], ['id' => 'aaaaaaaaaaaa1']];
        $first = $data['label_inventory']['containers'][0];
        $data['label_inventory']['containers'] = [
            [...$first, 'id' => 'aaaaaaaaaaaa1111'],
            [...$first, 'id' => 'bbbbbbbbbbbb1111', 'name' => 'other'],
        ];
        $this->sendInventory($host, $data)->assertUnprocessable()->assertJsonValidationErrors('label_inventory');
        $this->assertSame(1, $host->refresh()->agent_inventory_sequence);
        $this->assertSame($original, $job->refresh()->getAttributes());
    }

    public function test_stopped_container_boolean_is_strict_and_cannot_create_new_jobs(): void
    {
        $host = $this->host($this->destination());
        foreach ([0, '0'] as $value) {
            $data = $this->inventory($host);
            $data['label_inventory']['containers'][0]['running'] = $value;
            $this->sendInventory($host, $data)->assertUnprocessable()->assertJsonValidationErrors('label_inventory.containers.0.running');
            $this->assertSame(0, $host->refresh()->agent_inventory_sequence);
            $this->assertDatabaseCount('backup_jobs', 0);
        }
        $data['label_inventory']['containers'][0]['running'] = false;
        $this->sendInventory($host, $data)->assertOk();
        $this->assertSame(1, $host->refresh()->agent_inventory_sequence);
        $this->assertDatabaseCount('backup_jobs', 0);
    }

    public function test_oversized_optional_labels_become_an_accepted_incomplete_snapshot_preserving_jobs(): void
    {
        $host = $this->host($this->destination());
        $this->sendInventory($host, $this->inventory($host))->assertOk();
        $job = BackupJob::firstOrFail();
        $original = $job->getAttributes();
        $data = $this->inventory($host, 2);
        $data['label_inventory']['containers'][0]['labels']['dev.darkdragon14.volumevault.backup.mount'] = str_repeat('x', 16385);
        $bounded = AgentLabelInventory::bounded($data);
        $this->assertSame(['complete' => false, 'containers' => []], $bounded['label_inventory']);
        $this->sendInventory($host, $bounded)->assertOk();
        $this->assertSame(2, $host->refresh()->agent_inventory_sequence);
        $this->assertSame($original, $job->refresh()->getAttributes());
        $this->assertNotNull(DockerLabelBackupSetting::current($host->id)->last_sync_error);
    }

    public function test_busy_updates_wait_for_cleanup_and_only_apply_to_the_owning_host(): void
    {
        $destination = $this->destination();
        $a = $this->host($destination);
        $b = $this->host($destination);
        foreach ([$a, $b] as $host) {
            $this->sendInventory($host, $this->inventory($host))->assertOk();
        }
        $job = BackupJob::where('docker_host_id', $a->id)->firstOrFail();
        $other = BackupJob::where('docker_host_id', $b->id)->firstOrFail();
        $run = BackupRun::create(['docker_host_id' => $a->id, 'backup_job_id' => $job->id, 'trigger' => 'manual', 'status' => 'running']);
        $data = $this->inventory($a, 2);
        $data['label_inventory']['containers'][0]['labels']['dev.darkdragon14.volumevault.backup.retention-days'] = '7';
        $this->sendInventory($a, $data)->assertOk();
        $this->assertNull($job->refresh()->retention_days);
        $this->assertSame('apply', $job->pending_label_reconciliation['action']);
        $run->update(['status' => 'success', 'docker_container_cleanup_pending' => true]);
        $this->assertFalse(app(ApplyPendingDockerLabelReconciliation::class)->handle($job));
        $run->update(['docker_container_cleanup_pending' => false]);
        $this->assertTrue(app(ApplyPendingDockerLabelReconciliation::class)->handle($job));
        $this->assertSame(7, $job->refresh()->retention_days);
        $this->assertNull($other->refresh()->retention_days);
        $this->assertNull($job->pending_label_reconciliation);
    }

    public function test_settings_api_is_host_scoped_and_never_returns_destination_secrets(): void
    {
        $shared = $this->destination();
        $host = $this->host($shared);
        $foreign = $this->destination('Local only', DockerHost::LOCAL_ID);
        $user = User::factory()->create(['role' => 'admin']);
        $token = $user->createToken('settings', ['read', 'write'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/settings/docker-label-backups?docker_host_id='.$host->id)
            ->assertOk()->assertJsonPath('settings.docker_host_id', $host->id)->assertJsonCount(1, 'destinations')
            ->assertDontSee('destination-secret');
        $body = ['docker_host_id' => $host->id, 'enabled' => true, 'backup_destination_id' => $shared->id, ...DockerLabelBackupSetting::defaultValues()];
        $this->withToken($token)->putJson('/api/v1/settings/docker-label-backups', $body)->assertOk()->assertJsonPath('settings.enabled', true);
        $this->assertFalse(DockerLabelBackupSetting::current()->enabled);
        $this->withToken($token)->putJson('/api/v1/settings/docker-label-backups', [...$body, 'backup_destination_id' => $foreign->id])->assertUnprocessable();
        $this->withToken($token)->getJson('/api/v1/settings/docker-label-backups')->assertOk()->assertJsonPath('settings.docker_host_id', 1);
        $this->withToken($token)->getJson('/api/v1/settings/docker-label-backups?docker_host_id=99999')->assertUnprocessable();
        $readOnly = $user->createToken('read', ['read'])->plainTextToken;
        app('auth')->forgetGuards();
        $this->withToken($readOnly)->putJson('/api/v1/settings/docker-label-backups', $body)->assertForbidden();
    }

    public function test_global_notification_deletion_cleans_remote_defaults_and_deferred_references(): void
    {
        $host = $this->host($this->destination());
        $channel = NotificationChannel::create(['name' => 'Alerts', 'service' => NotificationChannel::SERVICE_ADVANCED, 'url' => 'ntfy://ntfy.sh/alerts', 'is_default' => false]);
        $settings = DockerLabelBackupSetting::current($host->id);
        $settings->update(['defaults' => [...$settings->resolvedDefaults(), 'notification_channel_ids' => [$channel->id]]]);
        $this->sendInventory($host, $this->inventory($host))->assertOk();
        $job = BackupJob::firstOrFail();
        $this->assertSame([$channel->id], $job->notificationChannels()->pluck('notification_channels.id')->all());
        BackupRun::create(['docker_host_id' => $host->id, 'backup_job_id' => $job->id, 'trigger' => 'manual', 'status' => 'running']);
        $data = $this->inventory($host, 2);
        $data['label_inventory']['containers'][0]['labels']['dev.darkdragon14.volumevault.backup.retention-days'] = '9';
        $this->sendInventory($host, $data)->assertOk();
        app(DeleteNotificationChannel::class)->handle($channel);
        $this->assertSame([], $settings->refresh()->resolvedDefaults()['notification_channel_ids']);
        $this->assertSame([], $job->refresh()->pending_label_reconciliation['notification_channel_ids']);
    }

    public function test_remote_default_destination_cannot_be_deactivated(): void
    {
        $destination = $this->destination();
        $this->host($destination);
        $this->expectException(DestinationMutationBlocked::class);
        app(MutateDestination::class)->setActive($destination, false);
    }

    public function test_settings_migration_preserves_the_existing_local_row_and_defaults(): void
    {
        $destination = $this->destination();
        $settings = DockerLabelBackupSetting::current();
        $settings->update(['enabled' => true, 'backup_destination_id' => $destination->id, 'defaults' => ['retention_days' => 42]]);
        $migration = require database_path('migrations/2026_09_18_151946_scope_docker_label_backup_settings_to_hosts.php');
        $migration->down();
        $migration->up();
        $this->assertSame($settings->id, DockerLabelBackupSetting::current()->id);
        $this->assertTrue(DockerLabelBackupSetting::current()->enabled);
        $this->assertSame($destination->id, DockerLabelBackupSetting::current()->backup_destination_id);
        $this->assertSame(42, DockerLabelBackupSetting::current()->resolvedDefaults()['retention_days']);
        $host = $this->host($destination);
        $this->assertSame($host->id, $host->dockerLabelBackupSetting->docker_host_id);
        $this->assertNotSame($settings->id, $host->dockerLabelBackupSetting->id);
    }

    private function destination(string $name = 'Shared S3', ?int $host = null): BackupDestination
    {
        return BackupDestination::create(['name' => $name, 'docker_host_id' => $host, 'provider' => $host === null ? 'custom_s3' : 'local', 'bucket' => 'backups', 'is_active' => true, 'access_key_id' => 'key', 'secret_access_key' => 'destination-secret', 'settings' => $host === null ? [] : ['archive_path' => '/remote-only']]);
    }

    private function host(BackupDestination $destination): DockerHost
    {
        $host = DockerHost::factory()->create();
        $host->forceFill(['agent_registered_at' => now(), 'agent_instance_id' => (string) Str::uuid(), 'agent_token_hash' => hash('sha256', str_repeat('a', 64)), 'agent_protocol_version' => 1, 'agent_capabilities' => AgentCompatibility::CAPABILITIES])->save();
        DockerLabelBackupSetting::current($host->id)->update(['enabled' => true, 'backup_destination_id' => $destination->id]);

        return $host;
    }

    private function inventory(DockerHost $host, int $sequence = 1, bool $enabled = true): array
    {
        return [
            'instance_id' => $host->agent_instance_id, 'protocol_version' => 1, 'sequence' => $sequence,
            'volumes' => [['name' => 'data', 'driver' => 'local']], 'host_path_allowlist' => [],
            'containers' => [['id' => str_repeat('a', 64), 'names' => 'app', 'state' => 'running']],
            'label_inventory' => ['complete' => true, 'containers' => [[
                'id' => str_repeat('a', 64), 'name' => 'app', 'created' => '2026-09-01T00:00:00Z', 'running' => true,
                'labels' => $enabled ? ['dev.darkdragon14.volumevault.enable' => 'true', 'dev.darkdragon14.volumevault.backup.mount' => '/data'] : [],
                'mounts' => [['name' => 'data', 'destination' => '/data']],
            ]]],
        ];
    }

    private function sendInventory(DockerHost $host, array $data): TestResponse
    {
        return $this->postJson('https://localhost/agent/v1/inventory', $data, ['Authorization' => 'Bearer '.$host->uuid.'.'.str_repeat('a', 64)]);
    }
}
