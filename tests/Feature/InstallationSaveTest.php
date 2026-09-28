<?php

namespace Tests\Feature;

use App\Actions\Backup\CreateBackupRunRecord;
use App\Models\AgentOperation;
use App\Models\ArchiveRelay;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\DockerHost;
use App\Models\NotificationChannel;
use App\Models\RestoreRun;
use App\Models\RunFinalization;
use App\Models\User;
use App\Services\Agents\AgentCompatibility;
use App\Services\Agents\ArchiveRelayStorage;
use App\Services\BackupDestinations\DestinationStorage;
use App\Services\Docker\DockerProcess;
use App\Services\InstallationSaves\CreateSecureInstallationSave;
use App\Services\InstallationSaves\GeneratedInstallationSave;
use App\Services\InstallationSaves\ImportSecureInstallationSave;
use App\Services\InstallationSaves\SecureSaveCrypto;
use App\Services\TwoFactor\TrustedDeviceManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class InstallationSaveTest extends TestCase
{
    private string $testStoragePath;

    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testStoragePath = base_path('storage/framework/testing/installation-save-'.Str::uuid());
        $this->databasePath = $this->testStoragePath.'/database/database.sqlite';

        $this->bootFileBackedStorage();
    }

    protected function tearDown(): void
    {
        DB::disconnect();
        File::deleteDirectory($this->testStoragePath);

        parent::tearDown();
    }

    public function test_secure_save_does_not_include_app_key_or_plaintext_secrets(): void
    {
        BackupDestination::create([
            'name' => 'S3',
            'provider' => BackupDestination::PROVIDER_AWS_S3,
            'bucket' => 'backups',
            'access_key_id' => 'secret-access-key-id',
            'secret_access_key' => 'secret-access-key',
        ]);

        $save = app(CreateSecureInstallationSave::class)->handle();
        $contents = File::get($save->path);

        $this->assertStringEndsWith('.vvsave', $save->filename);
        $this->assertStringNotContainsString((string) config('app.key'), $contents);
        $this->assertStringNotContainsString('secret-access-key-id', $contents);
        $this->assertStringNotContainsString('secret-access-key', $contents);
    }

    public function test_secure_save_import_restores_storage_and_reencrypts_secrets_with_current_app_key(): void
    {
        $user = User::factory()->admin()->create(['email' => 'owner@example.com']);
        $user->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => ['aaaaaaaaaa-bbbbbbbbbb'],
            'two_factor_confirmed_at' => now(),
        ])->save();
        $user->refresh();
        $user->twoFactorTrustedDevices()->create([
            'token' => hash('sha256', 'trusted-token'),
            'expires_at' => now()->addDays(TrustedDeviceManager::DAYS),
        ]);
        $destination = BackupDestination::create([
            'name' => 'S3',
            'provider' => BackupDestination::PROVIDER_AWS_S3,
            'bucket' => 'backups',
            'access_key_id' => 'old-access-key',
            'secret_access_key' => 'old-secret-key',
            'secrets' => ['private_key_passphrase' => 'old-passphrase'],
        ]);
        $channel = NotificationChannel::create([
            'name' => 'Ntfy',
            'service' => NotificationChannel::SERVICE_ADVANCED,
            'url' => 'ntfy://ntfy.sh/volumevault-secret-topic',
            'notification_level' => NotificationChannel::LEVEL_ERROR,
            'scope' => NotificationChannel::SCOPE_ALL,
        ]);
        DB::table('sessions')->insert([
            'id' => 'session-id',
            'payload' => 'payload',
            'last_activity' => time(),
        ]);
        DB::table('cache')->insert([
            'key' => 'cache-key',
            'value' => 'cache-value',
            'expiration' => time() + 3600,
        ]);
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'available_at' => time(),
            'created_at' => time(),
        ]);

        $previousAppKey = (string) config('app.key');
        $oldRawTwoFactorSecret = $user->getRawOriginal('two_factor_secret');
        $oldRawTwoFactorRecoveryCodes = $user->getRawOriginal('two_factor_recovery_codes');
        $oldRawSecret = $destination->getRawOriginal('secret_access_key');
        $oldRawUrl = $channel->getRawOriginal('url');
        $save = app(CreateSecureInstallationSave::class)->handle();
        $externalSavePath = sys_get_temp_dir().'/'.Str::uuid().'-'.$save->filename;
        File::copy($save->path, $externalSavePath);

        $this->bootFileBackedStorage('base64:'.base64_encode(str_repeat('n', 32)));

        app(ImportSecureInstallationSave::class)->handle($externalSavePath, $previousAppKey);

        $importedDestination = BackupDestination::firstOrFail();
        $importedChannel = NotificationChannel::firstOrFail();
        $importedUser = User::firstOrFail();

        $this->assertSame('owner@example.com', $importedUser->email);
        $this->assertSame('JBSWY3DPEHPK3PXP', $importedUser->two_factor_secret);
        $this->assertSame(['aaaaaaaaaa-bbbbbbbbbb'], $importedUser->two_factor_recovery_codes);
        $this->assertSame('old-access-key', $importedDestination->access_key_id);
        $this->assertSame('old-secret-key', $importedDestination->secret_access_key);
        $this->assertSame(['private_key_passphrase' => 'old-passphrase'], $importedDestination->secrets);
        $this->assertSame('ntfy://ntfy.sh/volumevault-secret-topic', $importedChannel->url);
        $this->assertNotSame($oldRawTwoFactorSecret, $importedUser->getRawOriginal('two_factor_secret'));
        $this->assertNotSame($oldRawTwoFactorRecoveryCodes, $importedUser->getRawOriginal('two_factor_recovery_codes'));
        $this->assertNotSame($oldRawSecret, $importedDestination->getRawOriginal('secret_access_key'));
        $this->assertNotSame($oldRawUrl, $importedChannel->getRawOriginal('url'));
        $this->assertDatabaseCount('sessions', 0);
        $this->assertDatabaseCount('cache', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('two_factor_trusted_devices', 0);

        File::delete($externalSavePath);
    }

    public function test_installation_save_screen_is_admin_only(): void
    {
        $this->mock(CreateSecureInstallationSave::class)->shouldNotReceive('handle');
        $this->actingAs(User::factory()->user()->create())
            ->get('/installation-save')
            ->assertForbidden();
        $this->get('/installation-save/download')->assertForbidden();
        $this->post('/installation-save/upload', ['backup_destination_id' => 1])->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())
            ->get('/installation-save')
            ->assertOk();
    }

    public function test_import_rotates_all_operation_secrets_and_private_relay_chunks_without_replaying_work(): void
    {
        Queue::fake();
        $this->mock(DockerProcess::class)->shouldNotReceive('run');
        $records = [];
        foreach (['pending', 'running', 'completed'] as $status) {
            [$operation, $relay, $run] = $this->relayRecords($status);
            $records[] = [$operation, $relay, $run];
        }
        $hosts = DockerHost::orderBy('id')->get()->map->getRawOriginal()->all();
        $oldKey = (string) config('app.key');
        $privatePath = storage_path('app/private/agent-tls/private.pem');
        File::ensureDirectoryExists(dirname($privatePath));
        File::put($privatePath, 'private-tls-material');
        $save = app(CreateSecureInstallationSave::class)->handle();
        $contents = File::get($save->path);
        foreach ([$oldKey, 'private-tls-material', 'archive-secret', 'delivery-secret', 'snapshot-secret', 'context-secret', 'result-secret', 'payload-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $contents);
        }
        $external = sys_get_temp_dir().'/'.Str::uuid().'.vvsave';
        File::copy($save->path, $external);

        try {
            $this->bootFileBackedStorage('base64:'.base64_encode(str_repeat('n', 32)));
            config(['app.previous_keys' => []]);
            app(ImportSecureInstallationSave::class)->handle($external, $oldKey);

            foreach ($records as [$operation, $relay, $run]) {
                $imported = $operation->fresh();
                foreach (['payload', 'context', 'delivery_token', 'result'] as $column) {
                    $plain = app(SecureSaveCrypto::class)->makeLaravelEncrypter($oldKey)->decryptString($operation->getRawOriginal($column));
                    $this->assertSame($column === 'delivery_token' ? $plain : json_decode($plain, true), $imported->{$column});
                    $this->assertNotSame($operation->getRawOriginal($column), $imported->getRawOriginal($column));
                    $this->assertSame(
                        app(SecureSaveCrypto::class)->makeLaravelEncrypter($oldKey)->decryptString($operation->getRawOriginal($column)),
                        Crypt::decryptString($imported->getRawOriginal($column)),
                    );
                }
                $this->assertSame(
                    collect($operation->getRawOriginal())->except(['payload', 'context', 'delivery_token', 'result'])->all(),
                    collect($imported->getRawOriginal())->except(['payload', 'context', 'delivery_token', 'result'])->all(),
                );
                $restoredRelay = $relay->fresh();
                $this->assertSame(['password' => 'snapshot-secret'], $restoredRelay->destination_snapshot);
                $this->assertNotSame($relay->getRawOriginal('destination_snapshot'), $restoredRelay->getRawOriginal('destination_snapshot'));
                $this->assertSame(
                    collect($relay->getRawOriginal())->except('destination_snapshot')->all(),
                    collect($restoredRelay->getRawOriginal())->except('destination_snapshot')->all(),
                );
                $this->assertSame($run->getRawOriginal(), $run->fresh()->getRawOriginal());
                $storage = app(ArchiveRelayStorage::class);
                $storage->verify($restoredRelay);
                $this->assertSame(str_repeat('x', ArchiveRelayStorage::CHUNK_BYTES), $storage->read($restoredRelay, 0));
                $this->assertSame('archive-secret', $storage->read($restoredRelay, ArchiveRelayStorage::CHUNK_BYTES));
                $this->assertSame(0600, fileperms($storage->directory($relay->id).'/0') & 0777);
                $this->assertSame(0700, fileperms($storage->directory($relay->id)) & 0777);
                $this->assertStringNotContainsString('context-secret', $imported->toJson());
                $this->assertStringNotContainsString('snapshot-secret', $restoredRelay->toJson());
            }
            $this->assertSame('private-tls-material', File::get($privatePath));
            $this->assertDatabaseCount('agent_operations', 3);
            $this->assertDatabaseCount('archive_relays', 3);
            $this->assertDatabaseCount('jobs', 0);
            $this->assertSame($hosts, DockerHost::orderBy('id')->get()->map->getRawOriginal()->all());
            $this->assertStringNotContainsString('secret', DB::table('activity_logs')->get()->toJson());
            Queue::assertNothingPushed();
        } finally {
            File::delete($external);
        }
    }

    public function test_import_rotates_remote_metadata_retry_credentials_and_preserves_finalization_assignment(): void
    {
        Queue::fake();
        $this->mock(DockerProcess::class)->shouldNotReceive('run');
        [, , $restore] = $this->relayRecords('running');
        $restore->sourceDockerHost->forceFill(['agent_registered_at' => now(), 'agent_protocol_version' => 1,
            'agent_capabilities' => AgentCompatibility::CAPABILITIES])->save();
        $run = app(CreateBackupRunRecord::class)->handle($restore->job, ['status' => 'success', 'trigger' => 'manual']);
        $run->update(['archive_metadata_pending' => true]);
        $run->refresh();
        $payload = ['version' => 1, 'action' => 'metadata', 'limit' => 1,
            'destination' => ['name' => 'Remote archive', 'provider' => 'ssh',
                'settings' => ['host' => 'archive.example.test'],
                'secrets' => ['password' => 'metadata-retry-password', 'private_key_passphrase' => 'metadata-retry-passphrase']],
            'archive' => ['filename' => 'immutable.tar.gz', 'key' => null, 'size' => null]];
        $finalization = RunFinalization::create([
            'backup_run_id' => $run->id, 'type' => RunFinalization::TYPE_ARCHIVE_METADATA,
            'deduplication_key' => "backup-run:{$run->id}:archive-metadata",
            'status' => RunFinalization::STATUS_FAILED, 'attempts' => 2, 'available_at' => now()->addMinutes(5),
            'claimed_at' => now(), 'claim_token' => (string) Str::uuid(),
            'enqueued_at' => now(), 'enqueue_token' => (string) Str::uuid(),
            'last_error' => 'Remote host temporarily unavailable.',
            'context' => ['docker_host_id' => $restore->source_docker_host_id],
            'remote_metadata_payload' => $payload,
        ])->refresh();
        $oldKey = (string) config('app.key');
        $oldCiphertext = $finalization->getRawOriginal('remote_metadata_payload');
        $save = app(CreateSecureInstallationSave::class)->handle();
        foreach ($payload['destination']['secrets'] as $secret) {
            $this->assertStringNotContainsString($secret, $oldCiphertext);
            $this->assertStringNotContainsString($secret, File::get($save->path));
        }
        $external = sys_get_temp_dir().'/'.Str::uuid().'.vvsave';
        File::copy($save->path, $external);

        try {
            $this->bootFileBackedStorage('base64:'.base64_encode(str_repeat('n', 32)));
            config(['app.previous_keys' => []]);
            app(ImportSecureInstallationSave::class)->handle($external, $oldKey);
            $imported = $finalization->fresh();
            $this->assertSame($payload, $imported->remote_metadata_payload);
            $this->assertNull($imported->notification_snapshot);
            $this->assertSame($payload, json_decode(Crypt::decryptString($imported->getRawOriginal('remote_metadata_payload')), true));
            $this->assertNotSame($oldCiphertext, $imported->getRawOriginal('remote_metadata_payload'));
            $this->assertSame(
                collect($finalization->getRawOriginal())->except('remote_metadata_payload')->all(),
                collect($imported->getRawOriginal())->except('remote_metadata_payload')->all(),
            );
            $this->assertSame($run->getRawOriginal(), $run->fresh()->getRawOriginal());
            $this->assertTrue(RunFinalization::outstanding()->whereKey($finalization->id)->exists());
            $this->assertArrayNotHasKey('remote_metadata_payload', $imported->toArray());
            foreach ($payload['destination']['secrets'] as $secret) {
                $this->assertStringNotContainsString($secret, $imported->toJson());
                $this->assertStringNotContainsString($secret, DB::table('activity_logs')->get()->toJson());
            }
            $this->assertDatabaseCount('run_finalizations', 1);
            $this->assertDatabaseCount('agent_operations', 1);
            Queue::assertNothingPushed();
        } finally {
            File::delete($external);
        }
    }

    public function test_import_rotates_notification_snapshot_credentials_and_preserves_delivery_retry_state(): void
    {
        Queue::fake();
        $this->mock(DockerProcess::class)->shouldNotReceive('run');
        [$operation, , $run] = $this->relayRecords('running');
        $channel = NotificationChannel::create([
            'name' => 'Captured delivery channel', 'service' => NotificationChannel::SERVICE_ADVANCED,
            'url' => 'ntfy://notification-user:notification-snapshot-secret@ntfy.sh/private-topic',
            'notification_level' => NotificationChannel::LEVEL_ERROR, 'scope' => NotificationChannel::SCOPE_ALL,
            'title_template' => 'Backup {{ status }}', 'body_template' => 'Backup {{ job_name }}',
            'restore_title_template' => 'Restore {{ status }}', 'restore_body_template' => 'Restore {{ target_volume_name }}',
        ])->refresh();
        $snapshot = $channel->only(RunFinalization::NOTIFICATION_SNAPSHOT_FIELDS);
        $finalization = RunFinalization::create([
            'restore_run_id' => $run->id, 'notification_channel_id' => $channel->id,
            'type' => RunFinalization::TYPE_FINISHED_NOTIFICATION,
            'deduplication_key' => "restore-run:{$run->id}:finished-notification:channel:{$channel->id}",
            'status' => RunFinalization::STATUS_FAILED, 'attempts' => 2, 'available_at' => now()->addMinutes(5),
            'claimed_at' => now(), 'claim_token' => (string) Str::uuid(),
            'enqueued_at' => now(), 'enqueue_token' => (string) Str::uuid(),
            'last_error' => 'Delivery temporarily unavailable.',
            'context' => ['docker_host_id' => $run->target_docker_host_id],
            'notification_snapshot' => $snapshot,
        ])->refresh();
        $channel->update(['name' => 'Changed channel', 'url' => 'ntfy://ntfy.sh/different-topic']);
        $oldKey = (string) config('app.key');
        $oldCiphertext = $finalization->getRawOriginal('notification_snapshot');
        $save = app(CreateSecureInstallationSave::class)->handle();
        $this->assertStringNotContainsString('notification-snapshot-secret', $oldCiphertext);
        $this->assertStringNotContainsString('notification-snapshot-secret', File::get($save->path));
        $external = sys_get_temp_dir().'/'.Str::uuid().'.vvsave';
        File::copy($save->path, $external);

        try {
            $this->bootFileBackedStorage('base64:'.base64_encode(str_repeat('n', 32)));
            config(['app.previous_keys' => []]);
            app(ImportSecureInstallationSave::class)->handle($external, $oldKey);
            $imported = $finalization->fresh();
            $this->assertSame($snapshot, $imported->notification_snapshot);
            $this->assertSame($snapshot, json_decode(Crypt::decryptString($imported->getRawOriginal('notification_snapshot')), true));
            $this->assertNotSame($oldCiphertext, $imported->getRawOriginal('notification_snapshot'));
            $this->assertSame(
                collect($finalization->getRawOriginal())->except('notification_snapshot')->all(),
                collect($imported->getRawOriginal())->except('notification_snapshot')->all(),
            );
            $this->assertSame('ntfy://ntfy.sh/different-topic', $imported->notificationChannel->url);
            $this->assertTrue(RunFinalization::outstanding()->whereKey($finalization->id)->exists());
            $this->assertArrayNotHasKey('notification_snapshot', $imported->toArray());
            $this->assertStringNotContainsString('notification-snapshot-secret', $imported->toJson());
            $this->assertStringNotContainsString('notification-snapshot-secret', DB::table('activity_logs')->get()->toJson());
            $this->assertSame($run->getRawOriginal(), $run->fresh()->getRawOriginal());
            $this->assertSame(
                collect($operation->getRawOriginal())->except(['payload', 'context', 'delivery_token', 'result'])->all(),
                collect($operation->fresh()->getRawOriginal())->except(['payload', 'context', 'delivery_token', 'result'])->all(),
            );
            $this->assertSame('delivery-secret', $operation->fresh()->delivery_token);
            $this->assertDatabaseCount('run_finalizations', 1);
            $this->assertDatabaseCount('agent_operations', 1);
            Queue::assertNothingPushed();
        } finally {
            File::delete($external);
        }
    }

    #[DataProvider('brokenRelaySaves')]
    public function test_invalid_relay_storage_is_rejected_before_replacing_live_installation(string $fault): void
    {
        [$operation, $relay] = $this->relayRecords('running');
        $storage = app(ArchiveRelayStorage::class);
        if ($fault === 'missing') {
            File::delete($storage->directory($relay->id).'/0');
        } elseif ($fault === 'ciphertext') {
            File::put($storage->directory($relay->id).'/0', 'invalid-ciphertext');
        } else {
            $relay->update(['sha256' => str_repeat('0', 64)]);
        }
        $oldKey = (string) config('app.key');
        $save = app(CreateSecureInstallationSave::class)->handle();
        $external = sys_get_temp_dir().'/'.Str::uuid().'.vvsave';
        File::copy($save->path, $external);

        try {
            $this->bootFileBackedStorage('base64:'.base64_encode(str_repeat('n', 32)));
            $user = User::factory()->admin()->create();
            File::put(storage_path('app/private/live-marker'), 'preserve');
            DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);
            try {
                app(ImportSecureInstallationSave::class)->handle($external, $oldKey);
                $this->fail('An invalid relay save was imported.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('relay', $exception->getMessage());
                $this->assertStringNotContainsString('archive-secret', $exception->getMessage());
            }
            $this->assertSame('preserve', File::get(storage_path('app/private/live-marker')));
            $this->assertSame($user->email, User::findOrFail($user->id)->email);
            $this->assertDatabaseCount('jobs', 1);
            $this->assertDatabaseCount('agent_operations', 0);
            $this->assertDatabaseCount('archive_relays', 0);
        } finally {
            File::delete($external);
        }
    }

    public static function brokenRelaySaves(): array
    {
        return [['missing'], ['ciphertext'], ['digest']];
    }

    #[DataProvider('olderSchemas')]
    public function test_older_saves_allow_absent_optional_operation_tables_and_columns(bool $missingTables): void
    {
        Schema::table('run_finalizations', fn ($table) => $table->dropColumn(['remote_metadata_payload', 'notification_snapshot']));
        Schema::drop('archive_relays');
        if ($missingTables) {
            Schema::drop('agent_operations');
        } else {
            Schema::table('agent_operations', fn ($table) => $table->dropColumn('result'));
            AgentOperation::create(['id' => (string) Str::uuid(), 'docker_host_id' => DockerHost::LOCAL_ID,
                'kind' => 'restore', 'context' => ['private' => 'legacy-context'], 'delivery_token' => 'legacy-token']);
        }
        $oldKey = (string) config('app.key');
        $save = app(CreateSecureInstallationSave::class)->handle();
        $external = sys_get_temp_dir().'/'.Str::uuid().'.vvsave';
        File::copy($save->path, $external);
        try {
            $zipPath = sys_get_temp_dir().'/'.Str::uuid().'.zip';
            try {
                File::put($zipPath, app(SecureSaveCrypto::class)->decrypt(File::get($external), $oldKey));
                $zip = new ZipArchive;
                $this->assertTrue($zip->open($zipPath));
                $manifest = json_decode($zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
                unset($manifest['archive_relay']);
                $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
                $zip->close();
                File::put($external, app(SecureSaveCrypto::class)->encrypt(File::get($zipPath), $oldKey));
            } finally {
                File::delete($zipPath);
            }
            $this->bootFileBackedStorage('base64:'.base64_encode(str_repeat('n', 32)));
            app(ImportSecureInstallationSave::class)->handle($external, $oldKey);
            $this->assertFalse(Schema::hasTable('archive_relays'));
            $this->assertFalse(Schema::hasColumn('run_finalizations', 'notification_snapshot'));
            $this->assertSame(! $missingTables, Schema::hasTable('agent_operations'));
            if (! $missingTables) {
                $this->assertSame(['private' => 'legacy-context'], AgentOperation::firstOrFail()->context);
                $this->assertSame('legacy-token', AgentOperation::firstOrFail()->delivery_token);
            }
        } finally {
            File::delete($external);
        }
    }

    public static function olderSchemas(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('deploymentModes')]
    public function test_picker_and_upload_only_allow_destinations_reachable_from_central_host(string $mode): void
    {
        config(['volumevault.mode' => $mode]);
        $this->actingAs(User::factory()->admin()->create());
        $remote = DockerHost::factory()->create(['driver' => 'agent']);
        $network = $this->destination(['name' => 'A network', 'provider' => 'aws_s3', 'is_active' => true]);
        $allowed = [$network->id];
        $rejected = [];
        foreach (['local', 'docker_volume'] as $provider) {
            $central = $this->destination(['name' => 'B '.$provider, 'provider' => $provider, 'docker_host_id' => DockerHost::LOCAL_ID, 'is_active' => true]);
            $rejected[] = $this->destination(['provider' => $provider, 'docker_host_id' => $remote->id, 'is_active' => true])->id;
            if ($mode === 'hybrid') {
                $allowed[] = $central->id;
            } else {
                $rejected[] = $central->id;
            }
        }
        $rejected[] = $this->destination(['provider' => 'aws_s3', 'is_active' => false])->id;
        $this->get('/installation-save')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('InstallationSaves/Index')
            ->where('destinations', fn ($destinations) => collect($destinations)->pluck('id')->sort()->values()->all() === collect($allowed)->sort()->values()->all()));

        $this->mock(CreateSecureInstallationSave::class)->shouldNotReceive('handle');
        $this->mock(DestinationStorage::class)->shouldNotReceive('upload');
        $this->mock(DockerProcess::class)->shouldNotReceive('run');
        foreach ($rejected as $id) {
            $this->post('/installation-save/upload', ['backup_destination_id' => $id])->assertNotFound();
        }
        $this->assertDatabaseCount('activity_logs', 0);
    }

    #[DataProvider('allowedUploadTargets')]
    public function test_allowed_save_upload_runs_centrally_and_removes_generated_file(string $mode, string $provider): void
    {
        config(['volumevault.mode' => $mode]);
        $destination = $this->destination(['provider' => $provider, 'is_active' => true]);
        $path = storage_path('app/private/test.vvsave');
        File::put($path, 'encrypted-save');
        $this->mock(CreateSecureInstallationSave::class)->shouldReceive('handle')->once()
            ->andReturn(new GeneratedInstallationSave($path, 'test.vvsave', 14));
        $this->mock(DestinationStorage::class)->shouldReceive('upload')->once()
            ->withArgs(fn (BackupDestination $selected, string $source, string $name, string $prefix) => $selected->id === $destination->id
                && $source === $path && $name === 'test.vvsave' && $prefix === 'installation-saves')
            ->andReturn('installation-saves/test.vvsave');
        $this->mock(DockerProcess::class)->shouldNotReceive('run');
        $this->actingAs(User::factory()->admin()->create())
            ->post('/installation-save/upload', ['backup_destination_id' => $destination->id])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertFileDoesNotExist($path);
    }

    public static function deploymentModes(): array
    {
        return [['hybrid'], ['orchestrator']];
    }

    public static function allowedUploadTargets(): array
    {
        return [['hybrid', 'aws_s3'], ['orchestrator', 'aws_s3'], ['hybrid', 'local'], ['hybrid', 'docker_volume']];
    }

    #[DataProvider('relayProgressStates')]
    public function test_import_preserves_unstarted_partial_and_cleaned_relay_progress(string $state): void
    {
        [$operation, $relay] = $this->relayRecords('pending');
        $directory = app(ArchiveRelayStorage::class)->directory($relay->id);
        if ($state === 'unstarted') {
            File::deleteDirectory($directory);
            $relay->update(['status' => 'pending', 'size_bytes' => null, 'sha256' => null, 'uploaded_bytes' => 0]);
            $operation->update(['payload' => null, 'context' => null, 'delivery_token' => null, 'result' => null]);
        } elseif ($state === 'partial') {
            File::delete($directory.'/'.ArchiveRelayStorage::CHUNK_BYTES);
            $relay->update(['status' => 'exporting', 'uploaded_bytes' => ArchiveRelayStorage::CHUNK_BYTES]);
        } else {
            File::deleteDirectory($directory);
            $relay->update(['status' => 'completed', 'cleaned_at' => now()]);
        }
        $metadata = collect($relay->fresh()->getRawOriginal())->except('destination_snapshot')->all();
        $oldKey = (string) config('app.key');
        $save = app(CreateSecureInstallationSave::class)->handle();
        $external = sys_get_temp_dir().'/'.Str::uuid().'.vvsave';
        File::copy($save->path, $external);
        try {
            $this->bootFileBackedStorage('base64:'.base64_encode(str_repeat('n', 32)));
            app(ImportSecureInstallationSave::class)->handle($external, $oldKey);
            $restored = $relay->fresh();
            $this->assertSame($metadata, collect($restored->getRawOriginal())->except('destination_snapshot')->all());
            $this->assertSame(['password' => 'snapshot-secret'], $restored->destination_snapshot);
            if ($state === 'partial') {
                $this->assertSame(str_repeat('x', ArchiveRelayStorage::CHUNK_BYTES), app(ArchiveRelayStorage::class)->read($restored, 0));
            } else {
                $this->assertDirectoryDoesNotExist($directory);
            }
            if ($state === 'unstarted') {
                foreach (['payload', 'context', 'delivery_token', 'result'] as $column) {
                    $this->assertNull($operation->fresh()->{$column});
                }
            }
        } finally {
            File::delete($external);
        }
    }

    public static function relayProgressStates(): array
    {
        return [['unstarted'], ['partial'], ['cleaned']];
    }

    private function relayRecords(string $status): array
    {
        $source = DockerHost::factory()->create(['driver' => 'agent']);
        $destination = $this->destination(['provider' => 'local', 'docker_host_id' => $source->id]);
        $job = BackupJob::create(['name' => 'Relay job', 'schedule_type' => 'daily', 'schedule_config' => ['time' => '00:00'],
            'volume_name' => 'source', 'source_type' => 'docker_volume', 'status' => 'active',
            'docker_host_id' => $source->id, 'backup_destination_id' => $destination->id]);
        $run = RestoreRun::create(['backup_job_id' => $job->id, 'backup_destination_id' => $destination->id,
            'source_docker_host_id' => $source->id, 'target_docker_host_id' => DockerHost::LOCAL_ID,
            'selected_backup_key' => 'immutable.tar.gz', 'source_volume_name' => 'source', 'target_volume_name' => 'restored-'.$status,
            'mode' => 'new_volume', 'status' => $status === 'completed' ? 'success' : ($status === 'pending' ? 'queued' : 'running')]);
        $run->forceFill(['target_volume_ownership_token' => 'ownership-token', 'dispatch_token' => (string) Str::uuid(),
            'dispatch_attempted_at' => now(), 'dispatch_published_at' => now()])->save();
        $operation = AgentOperation::create(['id' => (string) Str::uuid(), 'docker_host_id' => DockerHost::LOCAL_ID,
            'restore_run_id' => $run->id, 'kind' => 'restore', 'status' => $status,
            'payload' => ['password' => 'payload-secret'], 'context' => ['volume_lock_owner' => 'context-secret', 'local_relay_phase' => 'executing'],
            'delivery_token' => 'delivery-secret', 'result' => ['receipt' => 'result-secret'],
            'owner_instance_id' => (string) Str::uuid(), 'claimed_at' => now(), 'last_progress_at' => now(),
            'completed_at' => $status === 'completed' ? now() : null]);
        $relay = ArchiveRelay::create(['id' => (string) Str::uuid(), 'restore_run_id' => $run->id,
            'source_docker_host_id' => $source->id, 'target_docker_host_id' => DockerHost::LOCAL_ID,
            'source_agent_operation_id' => $operation->id, 'destination_snapshot' => ['password' => 'snapshot-secret'],
            'source_key' => 'immutable.tar.gz', 'reserved_bytes' => 2 * ArchiveRelayStorage::CHUNK_BYTES, 'expires_at' => now()->addHour()]);
        $bytes = str_repeat('x', ArchiveRelayStorage::CHUNK_BYTES).'archive-secret';
        $storage = app(ArchiveRelayStorage::class);
        $storage->upload($relay, 0, substr($bytes, 0, ArchiveRelayStorage::CHUNK_BYTES), strlen($bytes), hash('sha256', $bytes));
        $storage->upload($relay, ArchiveRelayStorage::CHUNK_BYTES, 'archive-secret', strlen($bytes), hash('sha256', $bytes));
        $relay->refresh()->update(['status' => $status === 'completed' ? 'completed' : ($status === 'pending' ? 'ready' : 'restoring')]);

        return [$operation->refresh(), $relay->refresh(), $run->refresh()];
    }

    private function destination(array $attributes): BackupDestination
    {
        return BackupDestination::create([...['name' => 'Destination', 'bucket' => 'archives', 'access_key_id' => '', 'secret_access_key' => '', 'is_active' => true], ...$attributes]);
    }

    private function bootFileBackedStorage(?string $appKey = null): void
    {
        DB::disconnect();
        File::deleteDirectory($this->testStoragePath);
        File::ensureDirectoryExists(dirname($this->databasePath));
        File::ensureDirectoryExists($this->testStoragePath.'/app/private');
        File::ensureDirectoryExists($this->testStoragePath.'/app/public');
        File::ensureDirectoryExists($this->testStoragePath.'/framework/cache');
        File::ensureDirectoryExists($this->testStoragePath.'/framework/sessions');
        File::ensureDirectoryExists($this->testStoragePath.'/framework/views');
        File::ensureDirectoryExists($this->testStoragePath.'/logs');
        File::put($this->databasePath, '');

        app()->useStoragePath($this->testStoragePath);

        config([
            'app.key' => $appKey ?: (string) config('app.key'),
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databasePath,
            'session.driver' => 'array',
            'volumevault.archive_relay.directory' => $this->testStoragePath.'/app/private/archive-relays',
        ]);

        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        Model::encryptUsing(null);
        DB::purge('sqlite');

        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->run();
    }
}
