<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestinationSecretsTest extends TestCase
{
    use RefreshDatabase;

    public function test_destination_credentials_are_encrypted_and_decryptable(): void
    {
        $destination = BackupDestination::create([
            'name' => 'R2',
            'provider' => BackupDestination::PROVIDER_CLOUDFLARE_R2,
            'endpoint' => 'https://account.r2.cloudflarestorage.com',
            'region' => 'auto',
            'bucket' => 'backups',
            'access_key_id' => 'plain-access-key',
            'secret_access_key' => 'plain-secret-key',
        ]);

        $this->assertNotSame('plain-access-key', $destination->getRawOriginal('access_key_id'));
        $this->assertNotSame('plain-secret-key', $destination->getRawOriginal('secret_access_key'));
        $this->assertSame('plain-access-key', $destination->access_key_id);
        $this->assertSame('plain-secret-key', $destination->secret_access_key);
    }

    public function test_frontend_serialization_does_not_expose_secrets(): void
    {
        $destination = BackupDestination::create([
            'name' => 'S3',
            'provider' => BackupDestination::PROVIDER_AWS_S3,
            'bucket' => 'backups',
            'access_key_id' => 'plain-access-key',
            'secret_access_key' => 'plain-secret-key',
        ]);

        $payload = $destination->safeForFrontend();

        $this->assertArrayNotHasKey('access_key_id', $payload);
        $this->assertArrayNotHasKey('secret_access_key', $payload);
        $this->assertStringNotContainsString('plain-access-key', json_encode($payload));
        $this->assertStringNotContainsString('plain-secret-key', json_encode($payload));
        $this->assertSame('********', $payload['masked_access_key_id']);
    }
}
