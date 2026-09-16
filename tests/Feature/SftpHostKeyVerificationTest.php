<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use App\Models\User;
use App\Services\BackupDestinations\DestinationStorage;
use App\Services\S3\S3ClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use phpseclib3\Net\SFTP;
use RuntimeException;
use Tests\TestCase;

class SftpHostKeyVerificationTest extends TestCase
{
    use RefreshDatabase;

    // Real ed25519 key + its `ssh-keygen -lf` fingerprint.
    private const PUBKEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIP7m24OrCqk9z3+lIB2Pa3L7Z5FkAOsXr7iKWvMkElYr';

    private const FINGERPRINT = 'SHA256:ev373BeFyQ16vITd1dmhbEyDfyqd4ZexqHYICIZ+9VU';

    // A different host key, to stand in for a man-in-the-middle server.
    private const OTHER_PUBKEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOF8jM81mWIRv8vwRltHg5eH9keV7GalxtABUIQK9r1P';

    public function test_fingerprint_matches_openssh_format(): void
    {
        $this->assertSame(self::FINGERPRINT, DestinationStorage::hostKeyFingerprint(self::PUBKEY));
    }

    public function test_matches_accepts_every_supported_pin_form(): void
    {
        // Full "type base64" line.
        $this->assertTrue(DestinationStorage::hostKeyMatches(self::PUBKEY, self::PUBKEY));
        // SHA256 fingerprint.
        $this->assertTrue(DestinationStorage::hostKeyMatches(self::FINGERPRINT, self::PUBKEY));
        // Lower-cased "sha256:" prefix still matches (digest stays case-sensitive).
        $this->assertTrue(DestinationStorage::hostKeyMatches('sha256:'.substr(self::FINGERPRINT, 7), self::PUBKEY));
        // Key line carrying a trailing comment.
        $this->assertTrue(DestinationStorage::hostKeyMatches(self::PUBKEY.' admin@host', self::PUBKEY));
        // Bare base64 blob.
        $this->assertTrue(DestinationStorage::hostKeyMatches(explode(' ', self::PUBKEY)[1], self::PUBKEY));
    }

    public function test_rsa_sha2_declarations_match_an_ssh_rsa_wire_blob(): void
    {
        $blob = base64_encode(pack('N', 7).'ssh-rsa'.pack('N', 3)."\x01\x00\x01".pack('N', 9).'rsa-bytes');
        $pinned = 'ssh-rsa '.$blob;

        foreach (['rsa-sha2-256', 'rsa-sha2-512'] as $algorithm) {
            $presented = $algorithm.' '.$blob;

            $this->assertNotSame('', DestinationStorage::hostKeyFingerprint($presented));
            $this->assertSame(DestinationStorage::hostKeyFingerprint($pinned), DestinationStorage::hostKeyFingerprint($presented));
            $this->assertTrue(DestinationStorage::hostKeyMatches($pinned, $presented));
        }
    }

    public function test_matches_rejects_a_different_key(): void
    {
        $this->assertFalse(DestinationStorage::hostKeyMatches(self::PUBKEY, self::OTHER_PUBKEY));
        $this->assertFalse(DestinationStorage::hostKeyMatches(self::FINGERPRINT, self::OTHER_PUBKEY));
    }

    public function test_malformed_key_line_does_not_use_a_base64_comment_as_the_key(): void
    {
        $blob = explode(' ', self::PUBKEY)[1];

        $this->assertSame('', DestinationStorage::hostKeyFingerprint('ssh-ed25519 not-base64 '.$blob));
        $this->assertSame('', DestinationStorage::hostKeyFingerprint('invalid-key-type '.$blob));
        $this->assertSame('', DestinationStorage::hostKeyFingerprint('ssh-rsa '.$blob));
        $this->assertFalse(DestinationStorage::hostKeyMatches('ssh-ed25519 not-base64 '.$blob, self::PUBKEY));
        $this->assertFalse(DestinationStorage::hostKeyMatches('ssh-rsa '.$blob, self::PUBKEY));
    }

    public function test_destination_fingerprint_uses_the_canonical_pinned_host_key(): void
    {
        $destination = $this->destination(self::PUBKEY.' first-comment');
        $fingerprint = $destination->locatorFingerprint();
        $destination->update(['settings' => [
            ...$destination->settings,
            'host_key' => self::PUBKEY.' changed-comment',
        ]]);
        $this->assertSame($fingerprint, $destination->fresh()->locatorFingerprint());

        $destination->update(['settings' => [
            ...$destination->settings,
            'host_key' => self::OTHER_PUBKEY,
        ]]);
        $this->assertNotSame($fingerprint, $destination->fresh()->locatorFingerprint());
    }

    public function test_destination_fingerprint_tracks_a_pinned_fingerprint_value(): void
    {
        $destination = $this->destination(self::FINGERPRINT);
        $fingerprint = $destination->locatorFingerprint();
        $destination->update(['settings' => [
            ...$destination->settings,
            'host_key' => DestinationStorage::hostKeyFingerprint(self::OTHER_PUBKEY),
        ]]);

        $this->assertNotSame($fingerprint, $destination->fresh()->locatorFingerprint());
    }

    public function test_verify_passes_when_server_key_matches_the_pin(): void
    {
        $sftp = Mockery::mock(SFTP::class);
        $sftp->shouldReceive('getServerPublicHostKey')->andReturn(self::PUBKEY);

        $this->storage()->callVerify($this->destination(self::FINGERPRINT), $sftp);

        $this->assertTrue(true); // reached only when no exception is thrown
    }

    public function test_verify_throws_when_the_server_key_does_not_match(): void
    {
        $sftp = Mockery::mock(SFTP::class);
        $sftp->shouldReceive('getServerPublicHostKey')->andReturn(self::OTHER_PUBKEY);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('host key verification failed');

        $this->storage()->callVerify($this->destination(self::FINGERPRINT), $sftp);
    }

    public function test_verify_is_skipped_when_no_host_key_is_pinned(): void
    {
        $sftp = Mockery::mock(SFTP::class);
        $sftp->shouldNotReceive('getServerPublicHostKey');

        $this->storage()->callVerify($this->destination(null), $sftp);

        $this->assertTrue(true);
    }

    public function test_probe_returns_the_presented_key_and_its_fingerprint(): void
    {
        $sftp = Mockery::mock(SFTP::class);
        $sftp->shouldReceive('getServerPublicHostKey')->andReturn(self::PUBKEY);

        $storage = new class(app(S3ClientFactory::class), $sftp) extends DestinationStorage
        {
            public function __construct(S3ClientFactory $factory, private readonly SFTP $sftpMock)
            {
                parent::__construct($factory);
            }

            protected function newSftp(string $host, int $port): SFTP
            {
                return $this->sftpMock;
            }
        };

        $result = $storage->probeHostKey('ssh.example.com', 22);

        $this->assertSame(self::PUBKEY, $result['key']);
        $this->assertSame(self::FINGERPRINT, $result['fingerprint']);
    }

    public function test_admin_can_fetch_a_server_host_key_in_one_click(): void
    {
        $this->mock(DestinationStorage::class)
            ->shouldReceive('probeHostKey')
            ->once()
            ->with('ssh.example.com', 2222)
            ->andReturn(['key' => self::PUBKEY, 'fingerprint' => self::FINGERPRINT]);

        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/destinations/host-key', ['host' => 'ssh.example.com', 'port' => 2222])
            ->assertOk()
            ->assertExactJson(['key' => self::PUBKEY, 'fingerprint' => self::FINGERPRINT]);
    }

    public function test_host_key_endpoint_requires_a_host(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/destinations/host-key', ['host' => ''])
            ->assertStatus(422);
    }

    public function test_host_key_endpoint_is_admin_only(): void
    {
        $this->actingAs(User::factory()->user()->create())
            ->postJson('/destinations/host-key', ['host' => 'ssh.example.com'])
            ->assertForbidden();
    }

    public function test_host_key_endpoint_reports_unreachable_servers(): void
    {
        $this->mock(DestinationStorage::class)
            ->shouldReceive('probeHostKey')
            ->andThrow(new RuntimeException('Connection timed out'));

        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/destinations/host-key', ['host' => 'ssh.example.com'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Connection timed out');
    }

    private function destination(?string $hostKey): BackupDestination
    {
        $settings = ['host' => 'ssh.example.com', 'port' => 22, 'remote_path' => '/srv'];

        if ($hostKey !== null) {
            $settings['host_key'] = $hostKey;
        }

        return BackupDestination::create([
            'name' => 'SFTP',
            'provider' => BackupDestination::PROVIDER_SSH,
            'bucket' => 'unused',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => $settings,
            'secrets' => ['user' => 'backup', 'password' => 'secret'],
        ]);
    }

    private function storage(): DestinationStorage
    {
        return new class(app(S3ClientFactory::class)) extends DestinationStorage
        {
            public function callVerify(BackupDestination $destination, SFTP $sftp): void
            {
                $this->verifyHostKey($destination, $sftp);
            }
        };
    }
}
