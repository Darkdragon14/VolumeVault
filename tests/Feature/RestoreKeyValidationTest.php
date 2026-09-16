<?php

namespace Tests\Feature;

use App\Actions\Backup\WithDockerLabelMutationLocks;
use App\Jobs\RunRestoreJob;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerVolume;
use App\Models\RestoreRun;
use App\Models\User;
use App\Services\BackupDestinations\ListBackupObjects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RestoreKeyValidationTest extends TestCase
{
    use RefreshDatabase;

    private string $archivePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->archivePath = sys_get_temp_dir().'/volumevault-restore-key-'.uniqid();
        config(['volumevault.host_path_allowlist' => [sys_get_temp_dir()]]);
        File::ensureDirectoryExists($this->archivePath);
        File::put($this->archivePath.'/backup.tar.gz', 'fake-archive');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->archivePath);

        parent::tearDown();
    }

    public function test_selected_backup_key_within_the_listing_is_accepted(): void
    {
        Queue::fake();
        $job = $this->backupJob();
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)
            ->post(route('backup-jobs.restore.store', $job), [
                'selected_backup_key' => 'backup.tar.gz',
                'mode' => RestoreRun::MODE_NEW_VOLUME,
            ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame(1, RestoreRun::count());
        // The restore records who initiated it for audit purposes.
        $this->assertSame($user->id, RestoreRun::first()->initiated_by_user_id);
    }

    public function test_restore_listing_does_not_expose_provider_error_details(): void
    {
        $job = $this->backupJob();
        $this->mock(ListBackupObjects::class)
            ->shouldReceive('handleForRun')
            ->once()
            ->andThrow(new \RuntimeException('provider secret: super-sensitive-token'));

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('backup-jobs.restore', $job))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('backups', [])
                ->where('listError', 'Unable to list backups from this destination.')
                ->where('listError', fn (string $error): bool => ! str_contains($error, 'super-sensitive-token')));
    }

    public function test_backup_listing_endpoints_do_not_expose_provider_error_details(): void
    {
        $job = $this->backupJob();
        $admin = User::factory()->admin()->create();
        $this->mock(ListBackupObjects::class)
            ->shouldReceive('handleForRun')
            ->twice()
            ->andThrow(new \RuntimeException('provider secret: super-sensitive-token'));

        $this->actingAs($admin)
            ->getJson(route('backup-jobs.backups', $job))
            ->assertStatus(502)
            ->assertExactJson(['message' => 'Unable to list backups from this destination.'])
            ->assertDontSee('super-sensitive-token');

        $token = $admin->createToken('restore-list', ['read'])->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/v1/backup-jobs/{$job->id}/backups")
            ->assertStatus(502)
            ->assertExactJson(['message' => 'Unable to list backups from this destination.'])
            ->assertDontSee('super-sensitive-token');
    }

    public function test_restore_page_attributes_archives_only_by_exact_provider_key(): void
    {
        File::ensureDirectoryExists($this->archivePath.'/nested');
        File::put($this->archivePath.'/nested/backup.tar.gz', 'unrelated-archive');
        $job = $this->backupJob();
        $this->successfulRun($job, $job->destination, 'backup.tar.gz');

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('backup-jobs.restore', $job))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('backups', function ($backups): bool {
                    $backupsByKey = collect($backups)->keyBy('key');

                    return $backupsByKey->get('backup.tar.gz')['belongs_to_job'] === true
                        && $backupsByKey->get('nested/backup.tar.gz')['belongs_to_job'] === false;
                })
                ->where('hasOtherBackups', true));
    }

    public function test_restore_page_scopes_exact_archive_keys_to_the_listed_destination(): void
    {
        $oldDestination = $this->localDestination('Old', 'old-destination', ['backup.tar.gz']);
        $currentDestination = $this->localDestination('Current', 'current-destination', ['backup.tar.gz']);
        $job = $this->backupJob($oldDestination);
        $this->successfulRun($job, $oldDestination, 'backup.tar.gz');
        $job->update(['backup_destination_id' => $currentDestination->id]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('backup-jobs.restore', $job))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('backups.0.key', 'backup.tar.gz')
                ->where('backups.0.belongs_to_job', false)
                ->where('hasOtherBackups', true));
    }

    public function test_snapshot_run_without_a_destination_fingerprint_is_not_attributed_to_the_job(): void
    {
        $job = $this->backupJob();
        BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_SUCCESS,
            'trigger' => BackupRun::TRIGGER_MANUAL,
            'backup_destination_id_snapshot' => $job->backup_destination_id,
            'backup_destination_locator_fingerprint' => null,
            'backup_key' => 'backup.tar.gz',
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('backup-jobs.restore', $job))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('backups.0.key', 'backup.tar.gz')
                ->where('backups.0.belongs_to_job', false)
                ->where('hasOtherBackups', true));
    }

    public function test_historical_restore_without_a_destination_fingerprint_is_rejected(): void
    {
        $job = $this->backupJob();
        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_SUCCESS,
            'trigger' => BackupRun::TRIGGER_MANUAL,
            'backup_destination_id_snapshot' => $job->backup_destination_id,
            'backup_destination_locator_fingerprint' => null,
            'backup_key' => 'backup.tar.gz',
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('backup-jobs.restore.store', $job), [
                'backup_run_id' => $run->id,
                'selected_backup_key' => 'backup.tar.gz',
                'mode' => RestoreRun::MODE_NEW_VOLUME,
            ])
            ->assertSessionHasErrors('destination');

        $this->assertSame(0, RestoreRun::count());
    }

    public function test_destination_fingerprint_changes_with_remote_account_identity(): void
    {
        $destination = BackupDestination::create([
            'name' => 'WebDAV',
            'provider' => BackupDestination::PROVIDER_WEBDAV,
            'bucket' => 'unused',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['url' => 'https://dav.example.test', 'path' => 'backups'],
            'secrets' => ['username' => 'first-account', 'password' => 'secret'],
        ]);
        $fingerprint = $destination->locatorFingerprint();
        $destination->update(['secrets' => ['username' => 'second-account', 'password' => 'secret']]);

        $this->assertNotSame($fingerprint, $destination->fresh()->locatorFingerprint());
    }

    public function test_azure_connection_string_account_name_participates_in_the_destination_fingerprint(): void
    {
        $destination = BackupDestination::create([
            'name' => 'Azure',
            'provider' => BackupDestination::PROVIDER_AZURE_BLOB,
            'bucket' => 'unused',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['container' => 'backups'],
            'secrets' => ['connection_string' => 'DefaultEndpointsProtocol=https;AccountName=firstaccount;AccountKey=secret'],
        ]);
        $fingerprint = $destination->locatorFingerprint();
        $destination->update([
            'secrets' => ['connection_string' => 'DefaultEndpointsProtocol=https;AccountName=secondaccount;AccountKey=secret'],
        ]);

        $this->assertNotSame($fingerprint, $destination->fresh()->locatorFingerprint());
    }

    public function test_azure_connection_string_endpoint_participates_in_the_destination_fingerprint(): void
    {
        $destination = BackupDestination::create([
            'name' => 'Azure',
            'provider' => BackupDestination::PROVIDER_AZURE_BLOB,
            'bucket' => 'unused',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['container' => 'backups'],
            'secrets' => ['connection_string' => 'AccountName=account;AccountKey=secret;EndpointSuffix=blob.core.windows.net'],
        ]);
        $fingerprint = $destination->locatorFingerprint();
        $destination->update([
            'secrets' => ['connection_string' => 'AccountName=account;AccountKey=secret;EndpointSuffix=blob.core.chinacloudapi.cn'],
        ]);

        $this->assertNotSame($fingerprint, $destination->fresh()->locatorFingerprint());
    }

    public function test_rotating_s3_credentials_does_not_change_the_destination_fingerprint(): void
    {
        $destination = BackupDestination::create([
            'name' => 'S3',
            'provider' => BackupDestination::PROVIDER_AWS_S3,
            'bucket' => 'backups',
            'access_key_id' => 'old-key',
            'secret_access_key' => 'old-secret',
            'settings' => ['region' => 'eu-west-1'],
        ]);
        $fingerprint = $destination->locatorFingerprint();
        $destination->update(['access_key_id' => 'new-key', 'secret_access_key' => 'new-secret']);

        $this->assertSame($fingerprint, $destination->fresh()->locatorFingerprint());
    }

    public function test_changing_only_dropbox_path_case_does_not_change_the_destination_fingerprint(): void
    {
        $destination = BackupDestination::create([
            'name' => 'Dropbox',
            'provider' => BackupDestination::PROVIDER_DROPBOX,
            'bucket' => 'dropbox',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['remote_path' => '/Backups/Nested'],
            'secrets' => ['app_key' => 'app', 'app_secret' => 'secret', 'refresh_token' => 'refresh'],
        ]);
        $fingerprint = $destination->locatorFingerprint();

        $destination->update(['settings' => ['remote_path' => '/backups/nested']]);

        $this->assertSame($fingerprint, $destination->fresh()->locatorFingerprint());
    }

    public function test_dropbox_listed_key_is_opaque_and_the_destination_is_listed_once(): void
    {
        Queue::fake();
        $path = '/backups/daily..archives/backup.tar.gz';
        $key = 'id:opaque-dropbox-file-id';
        Http::fake([
            'https://api.dropboxapi.com/oauth2/token' => Http::response(['access_token' => 'token']),
            'https://api.dropboxapi.com/2/files/list_folder' => Http::response([
                'entries' => [[
                    '.tag' => 'file',
                    'id' => $key,
                    'path_display' => $path,
                    'size' => 123,
                    'server_modified' => '2026-09-03T12:00:00Z',
                ]],
                'has_more' => false,
            ]),
        ]);
        $destination = BackupDestination::create([
            'name' => 'Dropbox',
            'provider' => BackupDestination::PROVIDER_DROPBOX,
            'bucket' => 'dropbox',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['remote_path' => '/backups'],
            'secrets' => ['app_key' => 'app', 'app_secret' => 'secret', 'refresh_token' => 'refresh'],
        ]);
        $job = $this->backupJob($destination);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('backup-jobs.restore.store', $job), [
                'selected_backup_key' => $key,
                'mode' => RestoreRun::MODE_NEW_VOLUME,
            ])
            ->assertSessionDoesntHaveErrors();

        $this->assertSame($key, RestoreRun::firstOrFail()->selected_backup_key);
        $listingRequests = Http::recorded(fn ($request): bool => $request->url() === 'https://api.dropboxapi.com/2/files/list_folder');

        $this->assertCount(1, $listingRequests);
        $this->assertSame('/backups', $listingRequests->first()[0]['path']);
    }

    public function test_legacy_dropbox_path_key_is_unverifiable_before_any_remote_calls(): void
    {
        $path = '/Backups/NESTED/backup.tar.gz';
        $key = 'id:opaque-dropbox-file-id';
        Http::fake([
            'https://api.dropboxapi.com/oauth2/token' => Http::response(['access_token' => 'token']),
            'https://api.dropboxapi.com/2/files/list_folder' => Http::response([
                'entries' => [[
                    '.tag' => 'file',
                    'id' => $key,
                    'path_display' => $path,
                    'path_lower' => '/backups/nested/backup.tar.gz',
                    'size' => 123,
                    'server_modified' => '2026-09-03T12:00:00Z',
                ]],
                'has_more' => false,
            ]),
            'https://api.dropboxapi.com/2/files/get_metadata' => Http::response([
                '.tag' => 'file',
                'id' => $key,
                'path_display' => $path,
                'path_lower' => '/backups/nested/backup.tar.gz',
                'size' => 123,
                'server_modified' => '2026-09-03T12:00:00Z',
            ]),
        ]);
        $destination = BackupDestination::create([
            'name' => 'Dropbox',
            'provider' => BackupDestination::PROVIDER_DROPBOX,
            'bucket' => 'dropbox',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['remote_path' => '/Backups/Nested'],
            'secrets' => ['app_key' => 'app', 'app_secret' => 'secret', 'refresh_token' => 'refresh'],
        ]);
        $job = $this->backupJob($destination);
        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_SUCCESS,
            'trigger' => BackupRun::TRIGGER_MANUAL,
            'backup_key' => $path,
            'backup_filename' => 'backup.tar.gz',
            'backup_destination_id_snapshot' => $destination->id,
            'backup_destination_locator_fingerprint' => $destination->locatorFingerprint(),
        ]);

        $query = '?'.http_build_query(['backup' => $path, 'backup_run_id' => $run->id]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('backup-jobs.restore', $job).$query)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('preselectedBackupKey', $path)
                ->where('backupRunUnverifiable', true)
                ->where('backups', [])
                ->where('listError', null));

        Queue::fake();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('backup-jobs.restore.store', $job), [
                'backup_run_id' => $run->id,
                'selected_backup_key' => $key,
                'mode' => RestoreRun::MODE_NEW_VOLUME,
            ])
            ->assertSessionHasErrors(['selected_backup_key' => ListBackupObjects::UNVERIFIABLE_RUN_MESSAGE]);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('backup-jobs.restore.store', $job), [
                'backup_run_id' => $run->id,
                'selected_backup_key' => $path,
                'mode' => RestoreRun::MODE_NEW_VOLUME,
            ])
            ->assertSessionHasErrors(['selected_backup_key' => ListBackupObjects::UNVERIFIABLE_RUN_MESSAGE]);

        $this->actingAs($admin)->getJson(route('backup-jobs.backups', $job).$query)
            ->assertUnprocessable()
            ->assertExactJson(['message' => ListBackupObjects::UNVERIFIABLE_RUN_MESSAGE]);

        $token = $admin->createToken('restore', ['read', 'write'])->plainTextToken;
        $this->withToken($token)->getJson("/api/v1/backup-jobs/{$job->id}/backups{$query}")
            ->assertUnprocessable()
            ->assertExactJson(['message' => ListBackupObjects::UNVERIFIABLE_RUN_MESSAGE]);
        $this->withToken($token)->postJson("/api/v1/backup-jobs/{$job->id}/restore", [
            'backup_run_id' => $run->id,
            'selected_backup_key' => $key,
            'mode' => RestoreRun::MODE_NEW_VOLUME,
        ])->assertUnprocessable()->assertJsonValidationErrors('selected_backup_key');

        try {
            app(ListBackupObjects::class)->handleForRun($destination, $run);
            $this->fail('An unverifiable historical run must be rejected.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertSame([ListBackupObjects::UNVERIFIABLE_RUN_MESSAGE], $exception->errors()['selected_backup_key']);
        }

        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('restore_runs', 0);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('backup-jobs.restore', $job))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('backups.0.key', $key)
                ->where('backups.0.belongs_to_job', false)
                ->where('backupRunUnverifiable', false)
                ->where('hasOtherBackups', true));
    }

    public function test_historical_dropbox_null_key_is_unverifiable_before_any_remote_calls(): void
    {
        Http::fake();
        $destination = new BackupDestination(['provider' => BackupDestination::PROVIDER_DROPBOX]);
        $run = new BackupRun(['backup_key' => null]);

        try {
            app(ListBackupObjects::class)->handleForRun($destination, $run);
            $this->fail('A historical run without a stable ID must be rejected.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertSame([ListBackupObjects::UNVERIFIABLE_RUN_MESSAGE], $exception->errors()['selected_backup_key']);
        }

        Http::assertNothingSent();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dropboxIdentityCases')]
    public function test_historical_dropbox_restore_requires_the_original_file_id(string $storedKey, string $selectedKey, string $remoteKey, string $remotePath, bool $accepted, bool $missingRoot = false): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $metadata = [
            '.tag' => 'file',
            'id' => $remoteKey,
            'path_display' => $remotePath,
            'path_lower' => strtolower($remotePath),
            'name' => basename($remotePath),
            'size' => 123,
            'server_modified' => '2026-09-03T12:00:00Z',
        ];
        Http::fake([
            'https://api.dropboxapi.com/oauth2/token' => Http::response(['access_token' => 'token']),
            'https://api.dropboxapi.com/2/files/list_folder' => $missingRoot
                ? Http::response(['error_summary' => 'path/not_found/', 'error' => ['.tag' => 'path', 'path' => ['.tag' => 'not_found']]], 409)
                : Http::response(['entries' => [$metadata], 'has_more' => false]),
            'https://api.dropboxapi.com/2/files/get_metadata' => $remoteKey === 'id:deleted'
                ? Http::response(['error_summary' => 'path/not_found/'], 409)
                : Http::response($metadata),
        ]);
        $destination = BackupDestination::create([
            'name' => 'Dropbox',
            'provider' => BackupDestination::PROVIDER_DROPBOX,
            'bucket' => 'dropbox',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['remote_path' => '/backups'],
            'secrets' => ['app_key' => 'app', 'app_secret' => 'secret', 'refresh_token' => 'refresh'],
        ]);
        $job = $this->backupJob($destination);
        $run = $this->successfulRun($job, $destination, $storedKey);
        $admin = User::factory()->admin()->create();
        $data = [
            'backup_run_id' => $run->id,
            'selected_backup_key' => $selectedKey,
            'mode' => RestoreRun::MODE_NEW_VOLUME,
        ];

        $response = $this->actingAs($admin)->post(route('backup-jobs.restore.store', $job), $data);

        if ($accepted) {
            $response->assertSessionDoesntHaveErrors();
            $this->assertSame($storedKey, RestoreRun::sole()->selected_backup_key);
            Queue::assertPushed(\App\Jobs\RunRestoreJob::class);
            $this->get(route('backup-jobs.restore', $job).'?backup_run_id='.$run->id)
                ->assertOk()->assertInertia(fn ($page) => $page
                    ->where('backupRunUnverifiable', false)
                    ->where('preselectedBackupKey', $storedKey)
                    ->where('backups', fn ($objects): bool => collect($objects)->contains(
                        fn ($object): bool => $object['key'] === $storedKey && $object['belongs_to_job'],
                    )));
            $token = $admin->createToken('restore', ['write'])->plainTextToken;
            $this->withToken($token)->postJson("/api/v1/backup-jobs/{$job->id}/restore", $data)->assertAccepted();
            $this->assertDatabaseCount('restore_runs', 2);
        } else {
            $response->assertSessionHasErrors('selected_backup_key');
            $token = $admin->createToken('restore', ['write'])->plainTextToken;
            $this->withToken($token)->postJson("/api/v1/backup-jobs/{$job->id}/restore", $data)
                ->assertUnprocessable()->assertJsonValidationErrors('selected_backup_key');
            $this->assertDatabaseCount('restore_runs', 0);
            Queue::assertNothingPushed();

            if ($selectedKey !== $storedKey) {
                Http::assertNothingSent();
            }
        }

        $originalExists = $remoteKey === $storedKey;
        $query = '?backup_run_id='.$run->id;
        $this->actingAs($admin)->get(route('backup-jobs.restore', $job).$query)
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('backupRunUnverifiable', false)
                ->where('preselectedBackupKey', $storedKey)
                ->where('listError', null)
                ->has('backups', $originalExists ? 1 : 0));

        $webListing = $this->getJson(route('backup-jobs.backups', $job).$query)
            ->assertOk()->assertJsonCount($originalExists ? 1 : 0, 'backups');
        $token = $admin->createToken('restore-list', ['read'])->plainTextToken;
        $apiListing = $this->withToken($token)->getJson("/api/v1/backup-jobs/{$job->id}/backups{$query}")
            ->assertOk()->assertJsonCount($originalExists ? 1 : 0, 'data');

        if ($originalExists) {
            $webListing->assertJsonPath('backups.0.key', $storedKey)
                ->assertJsonPath('backups.0.display_name', basename($remotePath));
            $apiListing->assertJsonPath('data.0.key', $storedKey)
                ->assertJsonPath('data.0.display_name', basename($remotePath));
        }

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.dropboxapi.com/2/files/get_metadata'
            && $request['path'] === $storedKey);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/files/list_folder')
            || ($request->url() === 'https://api.dropboxapi.com/2/files/get_metadata' && $request['path'] !== $storedKey));
    }

    public static function dropboxIdentityCases(): array
    {
        return [
            'current ID' => ['id:original', 'id:original', 'id:original', '/backups/backup.tar.gz', true],
            'renamed ID' => ['id:original', 'id:original', 'id:original', '/backups/renamed.tar.gz', true],
            'moved ID' => ['id:original', 'id:original', 'id:original', '/elsewhere/renamed.tar.gz', true],
            'moved ID with missing configured root' => ['id:original', 'id:original', 'id:original', '/elsewhere/renamed.tar.gz', true, true],
            'replacement metadata with missing configured root' => ['id:original', 'id:original', 'id:replacement', '/elsewhere/renamed.tar.gz', false, true],
            'deleted ID with missing configured root' => ['id:original', 'id:original', 'id:deleted', '/elsewhere/renamed.tar.gz', false, true],
            'replacement selected' => ['id:original', 'id:replacement', 'id:replacement', '/backups/backup.tar.gz', false],
            'replacement returned for original' => ['id:original', 'id:original', 'id:replacement', '/backups/backup.tar.gz', false],
            'deleted original with same-path replacement listed' => ['id:original', 'id:original', 'id:deleted', '/backups/backup.tar.gz', false],
        ];
    }

    public function test_unlisted_path_traversal_key_is_rejected_by_the_exact_allow_list(): void
    {
        $job = $this->backupJob();

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('backup-jobs.restore', $job))
            ->post(route('backup-jobs.restore.store', $job), [
                'selected_backup_key' => '../../../../etc/passwd',
                'mode' => RestoreRun::MODE_NEW_VOLUME,
            ])
            ->assertSessionHasErrors('selected_backup_key');

        $this->assertSame(0, RestoreRun::count());
    }

    public function test_key_outside_the_listing_is_rejected(): void
    {
        $job = $this->backupJob();

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('backup-jobs.restore', $job))
            ->post(route('backup-jobs.restore.store', $job), [
                'selected_backup_key' => 'does-not-exist.tar.gz',
                'mode' => RestoreRun::MODE_NEW_VOLUME,
            ])
            ->assertSessionHasErrors('selected_backup_key');

        $this->assertSame(0, RestoreRun::count());
    }

    public function test_historical_run_uses_its_destination_after_the_job_moves(): void
    {
        Queue::fake();
        $destinationA = $this->localDestination('Destination A', 'destination-a', ['from-a.tar.gz']);
        $destinationB = $this->localDestination('Destination B', 'destination-b', ['from-b.tar.gz']);
        $job = $this->backupJob($destinationA);
        $run = $this->successfulRun($job, $destinationA, 'from-a.tar.gz');
        $job->update(['backup_destination_id' => $destinationB->id]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('backup-jobs.restore', $job).'?backup=from-a.tar.gz&backup_run_id='.$run->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('restoreDestination.id', $destinationA->id)
                ->where('backupRunId', $run->id)
                ->where('backups.0.key', 'from-a.tar.gz'));

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('backup-jobs.restore.store', $job), [
                'backup_run_id' => $run->id,
                'selected_backup_key' => 'from-a.tar.gz',
                'mode' => RestoreRun::MODE_NEW_VOLUME,
            ])
            ->assertSessionDoesntHaveErrors();

        $restore = RestoreRun::firstOrFail();
        $this->assertSame($destinationA->id, $restore->backup_destination_id);
        $this->assertSame('app_data', $restore->source_volume_name);
    }

    public function test_historical_in_place_restore_uses_the_backup_run_source_snapshot_after_job_retargeting(): void
    {
        Queue::fake();
        $destinationA = $this->localDestination('Destination A', 'source-a', ['from-a.tar.gz']);
        $destinationB = $this->localDestination('Destination B', 'source-b', ['from-b.tar.gz']);
        $job = $this->backupJob($destinationA);
        DockerVolume::create(['name' => $job->volume_name, 'exists' => true]);
        $run = $this->successfulRun($job, $destinationA, 'from-a.tar.gz');
        $run->forceFill([
            'source_type_snapshot' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
            'source_volume_name' => 'app_data',
        ])->save();
        $job->update([
            'volume_name' => 'retargeted_data',
            'backup_destination_id' => $destinationB->id,
        ]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('backup-jobs.restore', $job).'?backup=from-a.tar.gz&backup_run_id='.$run->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sourceVolumeName', 'app_data')
                ->where('isDockerVolumeSource', true));

        $this->actingAs($admin)
            ->post(route('backup-jobs.restore.store', $job), [
                'backup_run_id' => $run->id,
                'selected_backup_key' => 'from-a.tar.gz',
                'mode' => RestoreRun::MODE_INPLACE,
                'confirmation_text' => 'retargeted_data',
            ])
            ->assertSessionHasErrors('confirmation_text');

        $this->actingAs($admin)
            ->post(route('backup-jobs.restore.store', $job), [
                'backup_run_id' => $run->id,
                'selected_backup_key' => 'from-a.tar.gz',
                'mode' => RestoreRun::MODE_INPLACE,
                'backup_before_overwrite' => true,
                'confirmation_text' => 'app_data',
            ])
            ->assertSessionDoesntHaveErrors();

        $restore = RestoreRun::sole();
        $this->assertSame($destinationA->id, $restore->backup_destination_id);
        $this->assertSame('app_data', $restore->source_volume_name);
        $this->assertSame('app_data', $restore->target_volume_name);
        $this->assertTrue($restore->backup_before_overwrite);
    }

    public function test_legacy_historical_run_without_a_source_snapshot_cannot_restore_in_place(): void
    {
        $destination = $this->localDestination('Historical', 'legacy-source', ['legacy.tar.gz']);
        $job = $this->backupJob($destination);
        $run = $this->successfulRun($job, $destination, 'legacy.tar.gz');

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('backup-jobs.restore.store', $job), [
                'backup_run_id' => $run->id,
                'selected_backup_key' => 'legacy.tar.gz',
                'mode' => RestoreRun::MODE_INPLACE,
                'confirmation_text' => 'app_data',
            ])
            ->assertSessionHasErrors('mode');

        $this->assertSame(0, RestoreRun::count());
    }

    public function test_historical_run_from_another_job_is_rejected(): void
    {
        $destination = $this->localDestination('Shared', 'shared', ['backup.tar.gz']);
        $job = $this->backupJob($destination);
        $otherJob = $this->backupJob($destination, 'Other job');
        $run = $this->successfulRun($otherJob, $destination, 'backup.tar.gz');

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('backup-jobs.restore.store', $job), [
                'backup_run_id' => $run->id,
                'selected_backup_key' => 'backup.tar.gz',
                'mode' => RestoreRun::MODE_NEW_VOLUME,
            ])
            ->assertSessionHasErrors('backup_run_id');

        $this->assertSame(0, RestoreRun::count());
    }

    public function test_historical_run_with_a_deleted_destination_does_not_fall_back_to_the_current_destination(): void
    {
        $destinationA = $this->localDestination('Destination A', 'deleted-a', ['same-key.tar.gz']);
        $destinationB = $this->localDestination('Destination B', 'current-b', ['same-key.tar.gz']);
        $job = $this->backupJob($destinationA);
        $run = $this->successfulRun($job, $destinationA, 'same-key.tar.gz');
        $job->update(['backup_destination_id' => $destinationB->id]);
        $destinationA->delete();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('backup-jobs.restore.store', $job), [
                'backup_run_id' => $run->id,
                'selected_backup_key' => 'same-key.tar.gz',
                'mode' => RestoreRun::MODE_NEW_VOLUME,
            ])
            ->assertSessionHasErrors('destination');

        $this->assertSame(0, RestoreRun::count());
    }

    public function test_historical_run_with_an_inactive_destination_is_rejected(): void
    {
        $destinationA = $this->localDestination('Destination A', 'inactive-a', ['from-a.tar.gz']);
        $destinationB = $this->localDestination('Destination B', 'active-b', ['from-b.tar.gz']);
        $job = $this->backupJob($destinationA);
        $run = $this->successfulRun($job, $destinationA, 'from-a.tar.gz');
        $job->update(['backup_destination_id' => $destinationB->id]);
        $destinationA->update(['is_active' => false]);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('backup-jobs.restore.store', $job), [
                'backup_run_id' => $run->id,
                'selected_backup_key' => 'from-a.tar.gz',
                'mode' => RestoreRun::MODE_NEW_VOLUME,
            ])
            ->assertSessionHasErrors('destination');

        $this->assertSame(0, RestoreRun::count());
    }

    public function test_historical_run_is_rejected_after_its_destination_location_changes(): void
    {
        $destination = $this->localDestination('Historical', 'original-location', ['backup.tar.gz']);
        $job = $this->backupJob($destination);
        $run = $this->successfulRun($job, $destination, 'backup.tar.gz');
        $newPath = $this->archivePath.'/new-location';
        File::ensureDirectoryExists($newPath);
        File::put($newPath.'/backup.tar.gz', 'different-archive');
        $destination->update(['settings' => ['archive_path' => $newPath]]);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('backup-jobs.restore.store', $job), [
                'backup_run_id' => $run->id,
                'selected_backup_key' => 'backup.tar.gz',
                'mode' => RestoreRun::MODE_NEW_VOLUME,
            ])
            ->assertSessionHasErrors('destination');

        $this->assertSame(0, RestoreRun::count());
    }

    public function test_inactive_current_destination_is_rejected_by_restore_page_and_backup_lists(): void
    {
        $destination = $this->localDestination('Inactive', 'inactive-current', ['backup.tar.gz']);
        $destination->update(['is_active' => false]);
        $job = $this->backupJob($destination);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('backup-jobs.restore', $job))
            ->assertRedirect()
            ->assertSessionHasErrors('destination');

        $this->actingAs($admin)
            ->getJson(route('backup-jobs.backups', $job))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('destination');

        $token = $admin->createToken('restore-list', ['read'])->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/v1/backup-jobs/{$job->id}/backups")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('destination');
    }

    public function test_inactive_historical_destination_is_rejected_by_restore_page_and_backup_lists(): void
    {
        $historicalDestination = $this->localDestination('Historical', 'inactive-historical', ['historical.tar.gz']);
        $currentDestination = $this->localDestination('Current', 'active-current', ['current.tar.gz']);
        $job = $this->backupJob($historicalDestination);
        $run = $this->successfulRun($job, $historicalDestination, 'historical.tar.gz');
        $job->update(['backup_destination_id' => $currentDestination->id]);
        $historicalDestination->update(['is_active' => false]);
        $admin = User::factory()->admin()->create();
        $query = '?backup_run_id='.$run->id;

        $this->actingAs($admin)
            ->get(route('backup-jobs.restore', $job).$query)
            ->assertRedirect()
            ->assertSessionHasErrors('destination');

        $this->actingAs($admin)
            ->getJson(route('backup-jobs.backups', $job).$query)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('destination');

        $token = $admin->createToken('restore-list', ['read'])->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/v1/backup-jobs/{$job->id}/backups{$query}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('destination');
    }

    public function test_historical_context_rejects_a_key_only_available_on_the_current_destination(): void
    {
        $destinationA = $this->localDestination('Destination A', 'historical-a', ['from-a.tar.gz']);
        $destinationB = $this->localDestination('Destination B', 'current-only-b', ['from-b.tar.gz']);
        $job = $this->backupJob($destinationA);
        $run = $this->successfulRun($job, $destinationA, 'from-a.tar.gz');
        $job->update(['backup_destination_id' => $destinationB->id]);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('backup-jobs.restore.store', $job), [
                'backup_run_id' => $run->id,
                'selected_backup_key' => 'from-b.tar.gz',
                'mode' => RestoreRun::MODE_NEW_VOLUME,
            ])
            ->assertSessionHasErrors('selected_backup_key');

        $this->assertSame(0, RestoreRun::count());
    }

    public function test_historical_context_rejects_another_key_from_the_same_destination(): void
    {
        $destination = $this->localDestination('Shared', 'historical-shared', ['from-a.tar.gz', 'from-b.tar.gz']);
        $job = $this->backupJob($destination);
        $run = $this->successfulRun($job, $destination, 'from-a.tar.gz');

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('backup-jobs.restore.store', $job), [
                'backup_run_id' => $run->id,
                'selected_backup_key' => 'from-b.tar.gz',
                'mode' => RestoreRun::MODE_NEW_VOLUME,
            ])
            ->assertSessionHasErrors('selected_backup_key');

        $this->assertSame(0, RestoreRun::count());
    }

    public function test_historical_key_beyond_the_default_listing_limit_is_accepted(): void
    {
        Queue::fake();
        $keys = [];

        for ($index = 0; $index < 1000; $index++) {
            $keys[] = sprintf('backup-%04d.tar.gz', $index);
        }

        $keys[] = 'zzzz-historical.tar.gz';
        $destination = $this->localDestination('Large history', 'large-history', $keys);
        $job = $this->backupJob($destination);
        $run = $this->successfulRun($job, $destination, 'zzzz-historical.tar.gz');

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('backup-jobs.restore.store', $job), [
                'backup_run_id' => $run->id,
                'selected_backup_key' => 'zzzz-historical.tar.gz',
                'mode' => RestoreRun::MODE_NEW_VOLUME,
            ])
            ->assertSessionDoesntHaveErrors();

        $this->assertSame('zzzz-historical.tar.gz', RestoreRun::sole()->selected_backup_key);
    }

    public function test_restore_without_run_context_still_uses_the_current_job_destination(): void
    {
        Queue::fake();
        $destinationA = $this->localDestination('Destination A', 'old-a', ['from-a.tar.gz']);
        $destinationB = $this->localDestination('Destination B', 'new-b', ['from-b.tar.gz']);
        $job = $this->backupJob($destinationA);
        $job->update(['backup_destination_id' => $destinationB->id]);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('backup-jobs.restore.store', $job), [
                'selected_backup_key' => 'from-b.tar.gz',
                'mode' => RestoreRun::MODE_NEW_VOLUME,
            ])
            ->assertSessionDoesntHaveErrors();

        $this->assertSame($destinationB->id, RestoreRun::firstOrFail()->backup_destination_id);
    }

    public function test_in_place_restore_requires_typed_confirmation_matching_the_volume_name(): void
    {
        $job = $this->backupJob();

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('backup-jobs.restore', $job))
            ->post(route('backup-jobs.restore.store', $job), [
                'selected_backup_key' => 'backup.tar.gz',
                'mode' => RestoreRun::MODE_INPLACE,
                'confirmation_text' => 'wrong',
            ])
            ->assertSessionHasErrors('confirmation_text');

        $this->assertSame(0, RestoreRun::count());
    }

    #[DataProvider('inPlaceModes')]
    public function test_in_place_restore_is_accepted_with_the_exact_volume_name(string $mode): void
    {
        Queue::fake();
        $job = $this->backupJob();
        DockerVolume::create(['name' => $job->volume_name, 'exists' => true]);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('backup-jobs.restore.store', $job), [
                'selected_backup_key' => 'backup.tar.gz',
                'mode' => $mode,
                'confirmation_text' => 'app_data',
            ])
            ->assertSessionDoesntHaveErrors();

        $run = RestoreRun::first();
        $this->assertNotNull($run);
        $this->assertSame($mode, $run->mode);
        // In-place restores overwrite the source volume itself.
        $this->assertSame('app_data', $run->target_volume_name);
        Queue::assertPushed(RunRestoreJob::class);
    }

    public static function inPlaceModes(): array
    {
        return [
            'in-place' => [RestoreRun::MODE_INPLACE],
            'safe-inplace' => [RestoreRun::MODE_SAFE_INPLACE],
        ];
    }

    #[DataProvider('inPlaceModes')]
    public function test_in_place_restore_requires_an_available_source_volume_row(string $mode): void
    {
        Queue::fake();
        $job = $this->backupJob();
        $this->actingAs(User::factory()->admin()->create());

        foreach ([null, false] as $available) {
            if ($available !== null) {
                DockerVolume::create(['name' => $job->volume_name, 'exists' => $available]);
            }

            $this->post(route('backup-jobs.restore.store', $job), [
                'selected_backup_key' => 'backup.tar.gz',
                'mode' => $mode,
                'confirmation_text' => $job->volume_name,
            ])->assertSessionHasErrors('mode');

            $this->assertDatabaseCount('restore_runs', 0);
            Queue::assertNothingPushed();
        }
    }

    #[DataProvider('inPlaceSourceContexts')]
    public function test_in_place_restore_rejects_a_source_that_becomes_missing_before_lock_acquisition(string $mode, bool $historical): void
    {
        Queue::fake();
        $job = $this->backupJob();
        $source = DockerVolume::create(['name' => $job->volume_name, 'exists' => true]);
        $data = [
            'selected_backup_key' => 'backup.tar.gz',
            'mode' => $mode,
            'confirmation_text' => $source->name,
        ];

        if ($historical) {
            $backup = $this->successfulRun($job, $job->destination, 'backup.tar.gz');
            $backup->update([
                'source_type_snapshot' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
                'source_volume_name' => $source->name,
            ]);
            $data['backup_run_id'] = $backup->id;
            $job->update(['volume_name' => 'replacement_data']);
            DockerVolume::create(['name' => $job->volume_name, 'exists' => true]);
        }

        $locks = new class($source) extends WithDockerLabelMutationLocks
        {
            public bool $changed = false;

            public function __construct(private readonly DockerVolume $source) {}

            public function handleForJobs(array $managedJobIds, array $destinationIds, callable $callback, array $volumeNames = [], array $notificationChannelIds = [], array $explicitJobIds = []): mixed
            {
                $this->source->update(['exists' => false]);
                $this->changed = true;

                return parent::handleForJobs($managedJobIds, $destinationIds, $callback, $volumeNames, $notificationChannelIds, $explicitJobIds);
            }
        };
        $this->app->instance(WithDockerLabelMutationLocks::class, $locks);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('backup-jobs.restore.store', $job), $data)
            ->assertSessionHasErrors('mode');

        $this->assertTrue($locks->changed);
        $this->assertFalse($source->fresh()->isAvailable());
        $this->assertDatabaseCount('restore_runs', 0);
        $this->assertDatabaseMissing('activity_logs', ['event_type' => 'restore_run_started']);
        Queue::assertNothingPushed();
    }

    public static function inPlaceSourceContexts(): array
    {
        return [
            'in-place current source' => [RestoreRun::MODE_INPLACE, false],
            'safe-inplace current source' => [RestoreRun::MODE_SAFE_INPLACE, false],
            'in-place historical source' => [RestoreRun::MODE_INPLACE, true],
            'safe-inplace historical source' => [RestoreRun::MODE_SAFE_INPLACE, true],
        ];
    }

    public function test_new_volume_restore_is_accepted_when_the_source_is_missing_or_untracked(): void
    {
        Queue::fake();
        $job = $this->backupJob();
        $this->actingAs(User::factory()->admin()->create());

        foreach ([null, false] as $index => $available) {
            if ($available !== null) {
                DockerVolume::create(['name' => $job->volume_name, 'exists' => $available]);
            }

            $this->post(route('backup-jobs.restore.store', $job), [
                'selected_backup_key' => 'backup.tar.gz',
                'mode' => RestoreRun::MODE_NEW_VOLUME,
                'target_volume_name' => 'restored_data_'.$index,
            ])->assertSessionDoesntHaveErrors();

            $this->assertDatabaseHas('restore_runs', [
                'source_volume_name' => $job->volume_name,
                'target_volume_name' => 'restored_data_'.$index,
                'mode' => RestoreRun::MODE_NEW_VOLUME,
            ]);
        }

        Queue::assertPushed(RunRestoreJob::class, 2);
    }

    public function test_in_place_restore_is_rejected_for_host_path_sources(): void
    {
        $job = $this->backupJob();
        $job->forceFill(['source_type' => BackupJob::SOURCE_TYPE_HOST_PATH, 'host_path' => '/srv/data'])->save();

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('backup-jobs.restore', $job))
            ->post(route('backup-jobs.restore.store', $job), [
                'selected_backup_key' => 'backup.tar.gz',
                'mode' => RestoreRun::MODE_INPLACE,
                'confirmation_text' => '/srv/data',
            ])
            ->assertSessionHasErrors('mode');

        $this->assertSame(0, RestoreRun::count());
    }

    private function backupJob(?BackupDestination $destination = null, string $name = 'Job'): BackupJob
    {
        $destination ??= BackupDestination::create([
            'name' => 'Local',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['archive_path' => $this->archivePath],
        ]);

        return BackupJob::create([
            'name' => $name,
            'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function localDestination(string $name, string $directory, array $keys): BackupDestination
    {
        $path = $this->archivePath.'/'.$directory;
        File::ensureDirectoryExists($path);

        foreach ($keys as $key) {
            File::put($path.'/'.$key, 'fake-archive');
        }

        return BackupDestination::create([
            'name' => $name,
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['archive_path' => $path],
        ]);
    }

    private function successfulRun(BackupJob $job, BackupDestination $destination, string $key): BackupRun
    {
        return BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_SUCCESS,
            'trigger' => BackupRun::TRIGGER_MANUAL,
            'backup_destination_id_snapshot' => $destination->id,
            'backup_destination_locator_fingerprint' => $destination->locatorFingerprint(),
            'backup_key' => $key,
        ]);
    }
}
