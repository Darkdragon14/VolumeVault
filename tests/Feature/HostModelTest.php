<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerVolume;
use App\Models\Host;
use App\Models\RestoreRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HostModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_host_can_be_looked_up_by_type(): void
    {
        $localHost = Host::query()->local()->sole();

        $this->assertSame(Host::TYPE_LOCAL, $localHost->type);
        $this->assertSame(Host::STATUS_ONLINE, $localHost->status);
        $this->assertTrue($localHost->is_active);
    }

    public function test_host_relations_are_available_from_scoped_resources(): void
    {
        $host = Host::factory()->agent()->create(['name' => 'Remote Agent']);
        $destination = $this->destination();

        $volume = DockerVolume::create([
            'host_id' => $host->id,
            'name' => 'app_data',
            'exists' => true,
        ]);
        $job = BackupJob::create([
            'host_id' => $host->id,
            'name' => 'Nightly',
            'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);
        $backupRun = BackupRun::create([
            'host_id' => $host->id,
            'backup_job_id' => $job->id,
            'trigger' => BackupRun::TRIGGER_MANUAL,
        ]);
        $restoreRun = RestoreRun::create([
            'host_id' => $host->id,
            'backup_job_id' => $job->id,
            'backup_destination_id' => $destination->id,
            'selected_backup_key' => 'backups/app_data.tar.gz',
            'source_volume_name' => 'app_data',
            'target_volume_name' => 'app_data_restored',
        ]);

        $this->assertTrue($volume->host->is($host));
        $this->assertTrue($job->host->is($host));
        $this->assertTrue($backupRun->host->is($host));
        $this->assertTrue($restoreRun->host->is($host));

        $this->assertTrue($host->dockerVolumes()->whereKey($volume)->exists());
        $this->assertTrue($host->backupJobs()->whereKey($job)->exists());
        $this->assertTrue($host->backupRuns()->whereKey($backupRun)->exists());
        $this->assertTrue($host->restoreRuns()->whereKey($restoreRun)->exists());
    }

    public function test_host_casts_structured_attributes_and_hides_enrollment_token_hash(): void
    {
        $host = Host::factory()->agent()->create([
            'is_active' => false,
            'capabilities' => ['docker' => true],
            'metadata' => ['region' => 'lab'],
            'last_seen_at' => now(),
            'enrollment_token_hash' => 'hashed-token',
            'enrollment_token_expires_at' => now()->addHour(),
            'enrolled_at' => now(),
        ]);

        $this->assertFalse($host->is_active);
        $this->assertSame(['docker' => true], $host->capabilities);
        $this->assertSame(['region' => 'lab'], $host->metadata);
        $this->assertNotNull($host->last_seen_at);
        $this->assertNotNull($host->enrollment_token_expires_at);
        $this->assertNotNull($host->enrolled_at);
        $this->assertSame('hashed-token', $host->getRawOriginal('enrollment_token_hash'));
        $this->assertArrayNotHasKey('enrollment_token_hash', $host->toArray());
    }

    private function destination(): BackupDestination
    {
        return BackupDestination::create([
            'name' => 'S3',
            'provider' => BackupDestination::PROVIDER_AWS_S3,
            'bucket' => 'backups',
            'access_key_id' => 'access',
            'secret_access_key' => 'secret',
        ]);
    }
}
