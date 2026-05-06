<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Services\InstallationSaves\CreateSecureInstallationSave;
use App\Services\InstallationSaves\ImportSecureInstallationSave;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

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
        User::factory()->admin()->create(['email' => 'owner@example.com']);
        $destination = BackupDestination::create([
            'name' => 'S3',
            'provider' => BackupDestination::PROVIDER_AWS_S3,
            'bucket' => 'backups',
            'access_key_id' => 'old-access-key',
            'secret_access_key' => 'old-secret-key',
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
        $oldRawSecret = $destination->getRawOriginal('secret_access_key');
        $oldRawUrl = $channel->getRawOriginal('url');
        $save = app(CreateSecureInstallationSave::class)->handle();
        $externalSavePath = sys_get_temp_dir().'/'.Str::uuid().'-'.$save->filename;
        File::copy($save->path, $externalSavePath);

        $this->bootFileBackedStorage('base64:'.base64_encode(str_repeat('n', 32)));

        app(ImportSecureInstallationSave::class)->handle($externalSavePath, $previousAppKey);

        $importedDestination = BackupDestination::firstOrFail();
        $importedChannel = NotificationChannel::firstOrFail();

        $this->assertSame('owner@example.com', User::firstOrFail()->email);
        $this->assertSame('old-access-key', $importedDestination->access_key_id);
        $this->assertSame('old-secret-key', $importedDestination->secret_access_key);
        $this->assertSame('ntfy://ntfy.sh/volumevault-secret-topic', $importedChannel->url);
        $this->assertNotSame($oldRawSecret, $importedDestination->getRawOriginal('secret_access_key'));
        $this->assertNotSame($oldRawUrl, $importedChannel->getRawOriginal('url'));
        $this->assertDatabaseCount('sessions', 0);
        $this->assertDatabaseCount('cache', 0);
        $this->assertDatabaseCount('jobs', 0);

        File::delete($externalSavePath);
    }

    public function test_installation_save_screen_is_admin_only(): void
    {
        $this->actingAs(User::factory()->user()->create())
            ->get('/installation-save')
            ->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())
            ->get('/installation-save')
            ->assertOk();
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
        ]);

        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        DB::purge('sqlite');

        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->run();
    }
}
