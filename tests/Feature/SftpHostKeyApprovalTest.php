<?php

namespace Tests\Feature;

use App\Actions\Destinations\MutateDestination;
use App\Models\ActivityLog;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\RestoreRun;
use App\Models\User;
use App\Services\BackupDestinations\DestinationStorage;
use App\Support\SshHostKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SftpHostKeyApprovalTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIP7m24OrCqk9z3+lIB2Pa3L7Z5FkAOsXr7iKWvMkElYr';

    private const FINGERPRINT = 'SHA256:ev373BeFyQ16vITd1dmhbEyDfyqd4ZexqHYICIZ+9VU';

    private const OTHER_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOF8jM81mWIRv8vwRltHg5eH9keV7GalxtABUIQK9r1P';

    public function test_openapi_documents_explicit_approval_and_regular_save_guards(): void
    {
        $schema = $this->getJson('/api/v1/openapi.json')->assertOk()->json();
        $approval = $schema['paths']['/destinations/{destination}/host-key/approve']['post'];
        $this->assertSame('#/components/schemas/HostKeyApprovalRequest', $approval['requestBody']['content']['application/json']['schema']['$ref']);
        $this->assertContains('previous_fingerprint', $schema['components']['schemas']['HostKeyApprovalRequest']['required']);
        foreach (['DestinationCreateRequest', 'DestinationUpdateRequest'] as $request) {
            $this->assertSame('#/components/schemas/DestinationHostKeyApproval', $schema['components']['schemas'][$request]['properties']['host_key_approval']['$ref']);
        }
    }

    public function test_first_approval_canonicalizes_the_key_without_connecting_or_changing_configuration(): void
    {
        $destination = $this->destination();
        $before = $destination->getRawOriginal();
        $this->authenticateAdmin();
        $this->mock(DestinationStorage::class, function ($mock): void {
            $mock->shouldNotReceive('probeHostKey');
            $mock->shouldNotReceive('test');
        });

        $response = $this->postJson($this->url($destination), [
            ...$this->payload(),
            'key' => '  '.self::KEY.' discovered@remote-agent  ',
            'fingerprint' => 'sha256:'.substr(self::FINGERPRINT, 7).'=',
            'settings' => ['remote_path' => '/attacker'],
            'secrets' => ['password' => 'replacement'],
            'is_active' => true,
            'docker_host_id' => 123,
        ])->assertOk();

        $destination->refresh();
        $this->assertSame(self::KEY, $destination->setting('host_key'));
        $after = $destination->getRawOriginal();
        unset($before['settings'], $before['updated_at'], $before['storage_measurement_revision'], $after['settings'], $after['updated_at'], $after['storage_measurement_revision']);
        $this->assertSame($before, $after);
        $this->assertSame([
            'host' => 'server.local',
            'remote_path' => '/backups',
            'custom_option' => 'preserved',
            'host_key' => self::KEY,
        ], $destination->settings);

        $activity = ActivityLog::where('event_type', 'sftp_host_key_approved')->sole();
        $this->assertSame(['fingerprint' => self::FINGERPRINT, 'previous_fingerprint' => null], $activity->context);
        $this->assertSame($destination->id, $activity->subject_id);
        $this->assertSame(BackupDestination::class, $activity->subject_type);
        $this->assertSame(auth()->id(), $activity->user_id);
        foreach (['private-key-secret', 'password-secret', 'passphrase-secret', self::KEY] as $secret) {
            $this->assertStringNotContainsString($secret, $activity->toJson());
        }
        foreach (['private-key-secret', 'password-secret', 'passphrase-secret'] as $secret) {
            $response->assertDontSee($secret);
        }
    }

    #[DataProvider('savedPins')]
    public function test_explicit_replacement_accepts_each_saved_pin_format(string $pin): void
    {
        $destination = $this->destination(['host_key' => $pin]);
        $encryptedSecrets = $destination->getRawOriginal('secrets');
        $this->authenticateAdmin();

        $this->postJson($this->url($destination), [
            ...$this->payload(),
            'key' => explode(' ', self::OTHER_KEY)[1],
            'fingerprint' => SshHostKey::fingerprint(self::OTHER_KEY),
            'previous_fingerprint' => self::FINGERPRINT,
        ])->assertOk();

        $this->assertSame(self::OTHER_KEY, $destination->fresh()->setting('host_key'));
        $this->assertSame($encryptedSecrets, $destination->fresh()->getRawOriginal('secrets'));
        $this->assertSame([
            'fingerprint' => SshHostKey::fingerprint(self::OTHER_KEY),
            'previous_fingerprint' => self::FINGERPRINT,
        ], ActivityLog::where('event_type', 'sftp_host_key_approved')->sole()->context);
    }

    public static function savedPins(): array
    {
        return [
            'full key' => [self::KEY],
            'bare key' => [explode(' ', self::KEY)[1]],
            'fingerprint' => [self::FINGERPRINT],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_approval_does_not_change_the_destination(array $changes, ?string $missing, string $error): void
    {
        $destination = $this->destination();
        $before = $destination->getRawOriginal();
        $this->authenticateAdmin();
        $payload = [...$this->payload(), ...$changes];
        if ($missing !== null) {
            unset($payload[$missing]);
        }

        $this->postJson($this->url($destination), $payload)->assertUnprocessable()->assertJsonValidationErrors($error);

        $this->assertSame($before, $destination->fresh()->getRawOriginal());
        $this->assertDatabaseMissing('activity_logs', ['event_type' => 'sftp_host_key_approved']);
    }

    public static function invalidPayloads(): array
    {
        $cases = [];
        foreach (['host', 'port', 'key', 'fingerprint', 'previous_fingerprint'] as $field) {
            $cases['missing '.$field] = [[], $field, $field];
        }

        return [
            ...$cases,
            'wrong fingerprint' => [['fingerprint' => SshHostKey::fingerprint(self::OTHER_KEY)], null, 'fingerprint'],
            'malformed fingerprint' => [['fingerprint' => 'SHA256:invalid'], null, 'fingerprint'],
            'key instead of fingerprint' => [['fingerprint' => self::KEY], null, 'fingerprint'],
            'malformed key' => [['key' => 'ssh-ed25519 invalid'], null, 'fingerprint'],
            'fingerprint instead of key' => [['key' => self::FINGERPRINT], null, 'fingerprint'],
            'truncated key' => [['key' => 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519')], null, 'fingerprint'],
            'wrong host' => [['host' => 'other.local'], null, 'host'],
            'wrong port' => [['port' => 2222], null, 'host'],
            'unexpected previous pin' => [['previous_fingerprint' => self::FINGERPRINT], null, 'previous_fingerprint'],
        ];
    }

    #[DataProvider('stalePins')]
    public function test_replacement_requires_the_current_pin_fingerprint(?string $previous): void
    {
        $destination = $this->destination(['host_key' => self::OTHER_KEY]);
        $this->authenticateAdmin();

        $this->postJson($this->url($destination), [...$this->payload(), 'previous_fingerprint' => $previous])
            ->assertUnprocessable()->assertJsonValidationErrors('previous_fingerprint');

        $this->assertSame(self::OTHER_KEY, $destination->fresh()->setting('host_key'));
        $this->assertDatabaseMissing('activity_logs', ['event_type' => 'sftp_host_key_approved']);
    }

    public static function stalePins(): array
    {
        return ['implicit replacement' => [null], 'stale fingerprint' => [self::FINGERPRINT], 'malformed fingerprint' => ['SHA256:invalid'], 'full key not fingerprint' => [self::OTHER_KEY]];
    }

    public function test_non_ssh_destination_is_rejected(): void
    {
        $destination = $this->destination();
        $destination->update(['provider' => BackupDestination::PROVIDER_CUSTOM_S3]);
        $this->authenticateAdmin();

        $this->postJson($this->url($destination), $this->payload())->assertUnprocessable()->assertJsonValidationErrors('host');

        $this->assertNull($destination->fresh()->setting('host_key'));
    }

    #[DataProvider('configurationRuns')]
    public function test_queued_and_running_work_blocks_approval(string $kind, string $status): void
    {
        $destination = $this->destination();
        $job = BackupJob::create([
            'name' => 'Application', 'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *', 'status' => BackupJob::STATUS_ACTIVE,
        ]);
        if ($kind === 'backup') {
            BackupRun::create(['backup_job_id' => $job->id, 'status' => $status, 'trigger' => BackupRun::TRIGGER_MANUAL]);
        } else {
            RestoreRun::create([
                'backup_job_id' => $job->id, 'backup_destination_id' => $destination->id,
                'selected_backup_key' => 'backup.tar.gz', 'source_volume_name' => 'app_data',
                'target_volume_name' => 'restored_data', 'mode' => RestoreRun::MODE_NEW_VOLUME, 'status' => $status,
            ]);
        }
        $this->authenticateAdmin();

        $this->postJson($this->url($destination), $this->payload())->assertUnprocessable()->assertJsonValidationErrors('destination');

        $this->assertNull($destination->fresh()->setting('host_key'));
        $this->assertDatabaseMissing('activity_logs', ['event_type' => 'sftp_host_key_approved']);
    }

    public static function configurationRuns(): array
    {
        return [['backup', 'queued'], ['backup', 'running'], ['restore', 'queued'], ['restore', 'running']];
    }

    #[DataProvider('concurrentChanges')]
    public function test_service_rechecks_fresh_configuration_under_lock(string $field, mixed $value, string $error): void
    {
        $stale = $this->destination();
        $fresh = $stale->fresh();
        $fresh->update($field === 'provider' ? ['provider' => $value] : ['settings' => [...$fresh->settings, $field => $value]]);
        $before = $fresh->refresh()->getRawOriginal();

        try {
            app(MutateDestination::class)->approveSftpHostKey($stale, $this->payload());
            $this->fail('Approval accepted a stale destination model.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($error, $exception->errors());
        }

        $this->assertSame($before, $fresh->refresh()->getRawOriginal());
        $this->assertDatabaseMissing('activity_logs', ['event_type' => 'sftp_host_key_approved']);
    }

    public static function concurrentChanges(): array
    {
        return [
            'provider changed' => ['provider', BackupDestination::PROVIDER_CUSTOM_S3, 'host'],
            'host changed' => ['host', 'new.local', 'host'],
            'port changed' => ['port', 2222, 'host'],
            'pin changed' => ['host_key', self::OTHER_KEY, 'previous_fingerprint'],
        ];
    }

    #[DataProvider('unauthorizedUsers')]
    public function test_approval_requires_an_admin_with_write_ability(string $role, array $abilities, int $status): void
    {
        $destination = $this->destination();
        if ($role !== 'guest') {
            $user = $role === 'admin' ? User::factory()->admin()->create() : User::factory()->create();
            Sanctum::actingAs($user, $abilities);
        }

        $this->postJson($this->url($destination), $this->payload())->assertStatus($status);

        $this->assertNull($destination->fresh()->setting('host_key'));
        $this->assertDatabaseMissing('activity_logs', ['event_type' => 'sftp_host_key_approved']);
    }

    public static function unauthorizedUsers(): array
    {
        return ['guest' => ['guest', [], 401], 'non admin' => ['user', ['write'], 403], 'read only' => ['admin', ['read'], 403]];
    }

    #[DataProvider('regularSavePaths')]
    public function test_regular_save_accepts_explicit_approval_with_original_endpoint_guards(string $surface, bool $updating): void
    {
        $destination = $updating ? $this->destination(['host_key' => self::OTHER_KEY]) : null;
        $payload = $this->regularSavePayload($destination);

        $response = $this->regularSave($surface, $destination, $payload);
        if ($surface === 'web') {
            $response->assertRedirect('/destinations');
        } else {
            $response->assertStatus($updating ? 200 : 201);
        }

        $saved = $destination?->fresh() ?? BackupDestination::sole();
        $this->assertSame(self::KEY, $saved->setting('host_key'));
        $this->assertSame('new-server.local', $saved->setting('host'));
        $this->assertSame(2222, $saved->setting('port'));
        $this->assertSame('/new-backups', $saved->setting('remote_path'));
        $this->assertArrayNotHasKey('host_key_approval', $saved->settings);
    }

    #[DataProvider('regularSavePaths')]
    public function test_regular_save_rejects_a_key_that_does_not_match_the_approved_fingerprint(string $surface, bool $updating): void
    {
        $destination = $updating ? $this->destination(['host_key' => self::OTHER_KEY]) : null;
        $before = $destination?->getRawOriginal();
        $payload = $this->regularSavePayload($destination);
        $payload['host_key_approval']['fingerprint'] = SshHostKey::fingerprint(self::OTHER_KEY);

        $this->regularSave($surface, $destination, $payload)->assertUnprocessable()->assertJsonValidationErrors('settings.host_key');

        if ($destination !== null) {
            $this->assertSame($before, $destination->fresh()->getRawOriginal());
        } else {
            $this->assertDatabaseCount('backup_destinations', 0);
        }
    }

    #[DataProvider('regularSavePaths')]
    public function test_regular_manual_save_without_approval_remains_supported(string $surface, bool $updating): void
    {
        $destination = $updating ? $this->destination(['host_key' => self::OTHER_KEY]) : null;
        $payload = $this->regularSavePayload($destination);
        unset($payload['host_key_approval']);
        $payload['settings']['host_key'] = self::FINGERPRINT;

        $response = $this->regularSave($surface, $destination, $payload);
        if ($surface === 'web') {
            $response->assertRedirect('/destinations');
        } else {
            $response->assertStatus($updating ? 200 : 201);
        }

        $this->assertSame(self::FINGERPRINT, ($destination?->fresh() ?? BackupDestination::sole())->setting('host_key'));
    }

    #[DataProvider('regularSavePaths')]
    public function test_regular_save_accepts_a_null_optional_approval(string $surface, bool $updating): void
    {
        $destination = $updating ? $this->destination() : null;
        $payload = $this->regularSavePayload($destination);
        $payload['host_key_approval'] = null;

        $response = $this->regularSave($surface, $destination, $payload);
        if ($surface === 'web') {
            $response->assertRedirect('/destinations');
        } else {
            $response->assertStatus($updating ? 200 : 201);
        }

        $this->assertSame(self::KEY, ($destination?->fresh() ?? BackupDestination::sole())->setting('host_key'));
    }

    public static function regularSavePaths(): array
    {
        return ['web store' => ['web', false], 'web update' => ['web', true], 'api store' => ['api', false], 'api update' => ['api', true]];
    }

    #[DataProvider('nonNullCreateGuards')]
    public function test_regular_create_requires_null_previous_identity_fields(string $surface, string $field, mixed $value): void
    {
        $payload = $this->regularSavePayload();
        $payload['host_key_approval'][$field] = $value;

        $this->regularSave($surface, null, $payload)->assertUnprocessable()->assertJsonValidationErrors('settings.host_key');

        $this->assertDatabaseCount('backup_destinations', 0);
    }

    public static function nonNullCreateGuards(): array
    {
        $cases = [];
        foreach (['web', 'api'] as $surface) {
            foreach (['previous_host' => 'old.local', 'previous_port' => 22, 'previous_host_key' => self::OTHER_KEY] as $field => $value) {
                $cases[$surface.' '.$field] = [$surface, $field, $value];
            }
        }

        return $cases;
    }

    #[DataProvider('regularSaveConcurrentChanges')]
    public function test_regular_update_approval_rechecks_original_identity_from_a_stale_model(string $field, mixed $value): void
    {
        $stale = $this->destination(['host_key' => self::OTHER_KEY]);
        $payload = $this->regularSavePayload($stale);
        $fresh = $stale->fresh();
        $fresh->update(['settings' => [...$fresh->settings, $field => $value]]);
        $before = $fresh->refresh()->getRawOriginal();

        try {
            app(MutateDestination::class)->update($stale, $payload);
            $this->fail('Regular update accepted stale approval guards.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('settings.host_key', $exception->errors());
        }

        $this->assertSame($before, $fresh->refresh()->getRawOriginal());
    }

    public static function regularSaveConcurrentChanges(): array
    {
        return ['host' => ['host', 'concurrent.local'], 'port' => ['port', 2200], 'pin' => ['host_key', self::KEY]];
    }

    #[DataProvider('malformedRegularApprovals')]
    public function test_regular_save_rejects_malformed_approval_shapes(string $surface, bool $updating, mixed $approval, string $error): void
    {
        $destination = $updating ? $this->destination() : null;
        $before = $destination?->getRawOriginal();
        $payload = $this->regularSavePayload($destination);
        $payload['host_key_approval'] = $approval;

        $this->regularSave($surface, $destination, $payload)->assertUnprocessable()->assertJsonValidationErrors($error);

        if ($destination !== null) {
            $this->assertSame($before, $destination->fresh()->getRawOriginal());
        } else {
            $this->assertDatabaseCount('backup_destinations', 0);
        }
    }

    public static function malformedRegularApprovals(): array
    {
        $valid = ['fingerprint' => self::FINGERPRINT, 'previous_host' => null, 'previous_port' => null, 'previous_host_key' => null];
        $invalid = [
            'not array' => ['invalid', 'host_key_approval'],
            'unknown key' => [[...$valid, 'unexpected' => true], 'host_key_approval'],
            'fingerprint array' => [[...$valid, 'fingerprint' => []], 'host_key_approval.fingerprint'],
            'host array' => [[...$valid, 'previous_host' => ['host']], 'host_key_approval.previous_host'],
            'port string' => [[...$valid, 'previous_port' => 'ssh'], 'host_key_approval.previous_port'],
            'port zero' => [[...$valid, 'previous_port' => 0], 'host_key_approval.previous_port'],
            'port too large' => [[...$valid, 'previous_port' => 65536], 'host_key_approval.previous_port'],
            'key array' => [[...$valid, 'previous_host_key' => []], 'host_key_approval.previous_host_key'],
        ];
        foreach (array_keys($valid) as $field) {
            $missing = $valid;
            unset($missing[$field]);
            $invalid['missing '.$field] = [$missing, 'host_key_approval'];
        }
        $cases = [];
        foreach (self::regularSavePaths() as $path => [$surface, $updating]) {
            foreach ($invalid as $name => [$approval, $error]) {
                $cases[$path.' '.$name] = [$surface, $updating, $approval, $error];
            }
        }

        return $cases;
    }

    private function regularSave(string $surface, ?BackupDestination $destination, array $payload): TestResponse
    {
        $admin = User::factory()->admin()->create();
        if ($surface === 'web') {
            $this->actingAs($admin);
        } else {
            Sanctum::actingAs($admin, ['write']);
        }
        $url = ($surface === 'web' ? '' : '/api/v1').'/destinations'.($destination !== null ? '/'.$destination->id : '');

        return $destination !== null ? $this->putJson($url, $payload) : $this->postJson($url, $payload);
    }

    private function regularSavePayload(?BackupDestination $destination = null): array
    {
        return [
            'name' => 'Approved SFTP', 'provider' => BackupDestination::PROVIDER_SSH,
            'is_active' => true,
            'settings' => ['host' => 'new-server.local', 'port' => 2222, 'remote_path' => '/new-backups', 'host_key' => self::KEY],
            'secrets' => ['user' => 'backup', 'password' => 'password-secret'],
            'host_key_approval' => [
                'fingerprint' => self::FINGERPRINT,
                'previous_host' => $destination?->setting('host'),
                'previous_port' => $destination !== null ? (int) ($destination->setting('port') ?: 22) : null,
                'previous_host_key' => $destination?->setting('host_key'),
            ],
        ];
    }

    private function authenticateAdmin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create(), ['write']);
    }

    private function destination(array $settings = []): BackupDestination
    {
        return BackupDestination::create([
            'name' => 'SFTP', 'provider' => BackupDestination::PROVIDER_SSH,
            'endpoint' => 'server.local', 'bucket' => 'server.local:/backups', 'path_prefix' => '/backups',
            'access_key_id' => '', 'secret_access_key' => '',
            'settings' => ['host' => 'server.local', 'remote_path' => '/backups', 'custom_option' => 'preserved', ...$settings],
            'secrets' => ['user' => 'backup', 'password' => 'password-secret', 'private_key' => 'private-key-secret', 'private_key_passphrase' => 'passphrase-secret'],
            'is_active' => false,
        ])->refresh();
    }

    private function url(BackupDestination $destination): string
    {
        return '/api/v1/destinations/'.$destination->id.'/host-key/approve';
    }

    private function payload(): array
    {
        return ['host' => 'server.local', 'port' => 22, 'key' => self::KEY, 'fingerprint' => self::FINGERPRINT, 'previous_fingerprint' => null];
    }
}
