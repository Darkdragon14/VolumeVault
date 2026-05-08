<?php

namespace Tests\Feature;

use App\Actions\Backup\MarkMissingVolumeJobs;
use App\Actions\Docker\ListDockerVolumes;
use App\Actions\Docker\SyncDockerVolumes;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\DockerVolume;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class MissingVolumeDetectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_referencing_missing_volume_is_marked_error(): void
    {
        $job = BackupJob::create([
            'name' => 'Nightly',
            'volume_name' => 'missing_volume',
            'backup_destination_id' => $this->destination()->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);

        app(MarkMissingVolumeJobs::class)->handle(['missing_volume']);

        $job->refresh();

        $this->assertSame(BackupJob::STATUS_ERROR, $job->status);
        $this->assertSame('Docker volume not found: missing_volume', $job->last_error);
        $this->assertSame('Docker volume not found: missing_volume', $job->pause_reason);
    }

    public function test_sync_removes_missing_volume_without_jobs(): void
    {
        DockerVolume::create(['name' => 'orphaned_volume', 'exists' => true]);

        $result = $this->syncDockerVolumes([]);

        $this->assertSame(0, $result['marked_missing']);
        $this->assertSame(1, $result['removed']);
        $this->assertDatabaseMissing('docker_volumes', ['name' => 'orphaned_volume']);
    }

    public function test_sync_keeps_missing_volume_referenced_by_job(): void
    {
        DockerVolume::create(['name' => 'job_volume', 'exists' => true]);

        $job = BackupJob::create([
            'name' => 'Nightly',
            'volume_name' => 'job_volume',
            'backup_destination_id' => $this->destination()->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);

        $result = $this->syncDockerVolumes([]);

        $this->assertSame(1, $result['marked_missing']);
        $this->assertSame(0, $result['removed']);
        $this->assertDatabaseHas('docker_volumes', ['name' => 'job_volume', 'exists' => false]);
        $this->assertSame(BackupJob::STATUS_ERROR, $job->refresh()->status);
    }

    public function test_sync_removes_stale_missing_volume_after_last_job_is_deleted(): void
    {
        DockerVolume::create(['name' => 'stale_missing_volume', 'exists' => false]);

        $result = $this->syncDockerVolumes([]);

        $this->assertSame(1, $result['removed']);
        $this->assertDatabaseMissing('docker_volumes', ['name' => 'stale_missing_volume']);
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

    private function syncDockerVolumes(array $volumes): array
    {
        $listDockerVolumes = Mockery::mock(ListDockerVolumes::class);
        $listDockerVolumes->shouldReceive('handle')->once()->andReturn($volumes);

        return (new SyncDockerVolumes($listDockerVolumes, app(MarkMissingVolumeJobs::class)))->handle();
    }
}
