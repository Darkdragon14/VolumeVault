<?php

namespace Tests\Feature;

use App\Actions\Backup\MarkMissingVolumeJobs;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MissingVolumeDetectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_referencing_missing_volume_is_marked_error(): void
    {
        $destination = BackupDestination::create([
            'name' => 'S3',
            'provider' => BackupDestination::PROVIDER_AWS_S3,
            'bucket' => 'backups',
            'access_key_id' => 'access',
            'secret_access_key' => 'secret',
        ]);

        $job = BackupJob::create([
            'name' => 'Nightly',
            'volume_name' => 'missing_volume',
            'backup_destination_id' => $destination->id,
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
}
