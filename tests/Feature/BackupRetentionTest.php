<?php

namespace Tests\Feature;

use App\Actions\Backup\CreateBackupRunRecord;
use App\Actions\Backup\RenderBackupFilename;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BackupRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_archives_share_a_stable_job_namespace_even_with_custom_templates(): void
    {
        $job = $this->job();
        $job->update(['backup_filename_template' => 'custom-{id}']);
        $first = $this->createRun($job);
        $second = $this->createRun($job);
        $prefix = $first->execution_options_snapshot['backup_pruning_prefix'];

        $this->assertMatchesRegularExpression('/\Avolumevault-job-[0-9a-f-]{36}-\z/', $prefix);
        $this->assertSame($prefix, $second->execution_options_snapshot['backup_pruning_prefix']);
        $this->assertSame($prefix.'custom-'.$first->id.'.tar.gz', $first->backup_filename);
        $this->assertSame('volumevault-job-'.$job->fresh()->retention_uuid.'-', $prefix);
        $this->assertArrayNotHasKey('retention_count', $first->execution_options_snapshot);
        $job->update(['name' => 'Renamed', 'backup_filename_template' => 'changed-{id}']);
        $third = $this->createRun($job);
        $this->assertSame($prefix, $third->execution_options_snapshot['backup_pruning_prefix']);
        $this->assertSame($prefix.'changed-'.$third->id.'.tar.gz', $third->backup_filename);
    }

    public function test_jobs_with_identical_sources_and_templates_do_not_share_a_namespace(): void
    {
        $job = $this->job();
        $other = $job->replicate();
        $other->save();
        $first = $this->createRun($job);
        $second = $this->createRun($other);

        $this->assertNotSame($first->execution_options_snapshot['backup_pruning_prefix'], $second->execution_options_snapshot['backup_pruning_prefix']);
        $this->assertSame($first->backup_destination_id_snapshot, $second->backup_destination_id_snapshot);
    }

    public function test_historical_filenames_and_snapshots_remain_unchanged(): void
    {
        $job = $this->job();
        $historical = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_QUEUED,
            'trigger' => BackupRun::TRIGGER_MANUAL,
            'backup_filename' => 'old-custom.tar.gz',
            'execution_options_snapshot' => ['retention_days' => 7, 'retention_count' => 3],
        ]);
        $new = $this->createRun($job);

        $this->assertSame('old-custom.tar.gz', app(RenderBackupFilename::class)->handle($historical->fresh()));
        $this->assertSame(['retention_days' => 7, 'retention_count' => 3], $historical->fresh()->execution_options_snapshot);
        $this->assertStringStartsNotWith($new->execution_options_snapshot['backup_pruning_prefix'], $historical->backup_filename);
    }

    public function test_safety_archives_are_outside_the_jobs_pruning_namespace(): void
    {
        $job = $this->job();
        $ordinary = $this->createRun($job);
        $safety = app(CreateBackupRunRecord::class)->handle($job, [
            'status' => BackupRun::STATUS_QUEUED, 'trigger' => BackupRun::TRIGGER_PRE_RESTORE,
        ]);

        $this->assertNull($safety->execution_options_snapshot['backup_pruning_prefix']);
        $this->assertStringStartsWith('volumevault-safety-', $safety->backup_filename);
        $this->assertStringStartsNotWith($ordinary->execution_options_snapshot['backup_pruning_prefix'], $safety->backup_filename);
    }

    public function test_long_filenames_keep_the_complete_namespace_and_byte_limit(): void
    {
        $job = $this->job();
        $job->update(['name' => str_repeat('a', 255), 'backup_filename_template' => str_repeat('{name}', 20)]);
        $run = $this->createRun($job);

        $this->assertSame(255, strlen($run->backup_filename));
        $this->assertStringStartsWith($run->execution_options_snapshot['backup_pruning_prefix'], $run->backup_filename);
    }

    public function test_recreated_job_with_a_reused_id_cannot_prune_the_deleted_jobs_archives(): void
    {
        $job = $this->job();
        $original = $this->createRun($job);
        $prefix = $original->execution_options_snapshot['backup_pruning_prefix'];
        $jobId = $job->id;
        $job->delete();
        $replacement = $this->job();
        $replacement->forceFill(['id' => $jobId])->save();

        $new = $this->createRun($replacement);

        $this->assertNotSame($prefix, $new->execution_options_snapshot['backup_pruning_prefix']);
    }

    public function test_retention_namespace_migration_can_be_rolled_back_and_reapplied(): void
    {
        $migration = require database_path('migrations/2026_10_08_071227_add_retention_uuid_to_backup_jobs_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('backup_jobs', 'retention_uuid'));
        $migration->up();
        $this->assertTrue(Schema::hasColumn('backup_jobs', 'retention_uuid'));
    }

    public function test_safety_backups_can_be_created_on_a_resumed_agents_legacy_schema(): void
    {
        $job = $this->job();
        $migration = require database_path('migrations/2026_10_08_071227_add_retention_uuid_to_backup_jobs_table.php');
        $migration->down();
        try {
            $safety = app(CreateBackupRunRecord::class)->handle($job->fresh(), [
                'status' => BackupRun::STATUS_QUEUED, 'trigger' => BackupRun::TRIGGER_PRE_RESTORE,
            ]);

            $this->assertNull($safety->execution_options_snapshot['backup_pruning_prefix']);
            $this->assertStringStartsWith('volumevault-safety-', $safety->backup_filename);
        } finally {
            $migration->up();
        }
    }

    private function createRun(BackupJob $job): BackupRun
    {
        return app(CreateBackupRunRecord::class)->handle($job, [
            'status' => BackupRun::STATUS_QUEUED, 'trigger' => BackupRun::TRIGGER_MANUAL,
        ]);
    }

    private function job(): BackupJob
    {
        $destination = BackupDestination::create([
            'name' => 'S3', 'provider' => BackupDestination::PROVIDER_AWS_S3,
            'bucket' => 'backups', 'access_key_id' => 'key', 'secret_access_key' => 'secret',
        ]);

        return BackupJob::create([
            'name' => 'Job', 'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id, 'retention_days' => 7,
            'schedule_type' => BackupJob::SCHEDULE_DAILY, 'schedule_config' => ['time' => '02:00'],
            'status' => BackupJob::STATUS_ACTIVE,
        ]);
    }
}
