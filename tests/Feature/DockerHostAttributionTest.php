<?php

namespace Tests\Feature;

use App\Actions\Backup\CreateBackupRunRecord;
use App\Actions\Backup\RunBackup;
use App\Actions\Docker\RunBackupContainer;
use App\Actions\Docker\RunRestoreContainer;
use App\Actions\Restore\RunRestore;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\DockerHost;
use App\Models\DockerVolume;
use App\Models\RestoreRun;
use App\Services\BackupDestinations\DestinationStorage;
use App\Services\Docker\DockerProcess;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DockerHostAttributionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->beforeApplicationDestroyed(function (): void {
            $this->artisan('db:wipe', ['--force' => true])->assertSuccessful();
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_existing_records_are_backfilled_without_changing_history_or_secrets(): void
    {
        $destination = $this->destination();
        $destination->update(['provider' => BackupDestination::PROVIDER_LOCAL, 'settings' => ['archive_path' => '/backups']]);
        $networkDestination = $this->destination();
        $job = $this->job($destination);
        $volume = DockerVolume::create(['name' => 'shared']);
        $backup = app(CreateBackupRunRecord::class)->handle($job, ['trigger' => 'manual', 'status' => 'success', 'logs' => 'Historical backup']);
        $restore = $this->restore($job);
        $encryptedSecret = $destination->getRawOriginal('secret_access_key');
        $fingerprint = $destination->locatorFingerprint();
        $migration = require database_path('migrations/2026_09_17_101431_add_docker_host_attribution.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('docker_hosts'));
        $migration->up();

        $this->assertTrue(DockerHost::findOrFail(DockerHost::LOCAL_ID)->isLocal());
        foreach ([$job, $volume, $backup, $destination] as $model) {
            $this->assertSame(DockerHost::LOCAL_ID, $model->refresh()->docker_host_id);
        }
        $this->assertNull($networkDestination->refresh()->docker_host_id);
        $this->assertSame(DockerHost::LOCAL_ID, $restore->refresh()->source_docker_host_id);
        $this->assertSame(DockerHost::LOCAL_ID, $restore->target_docker_host_id);
        $this->assertSame('Historical backup', $backup->logs);
        $this->assertSame($encryptedSecret, $destination->getRawOriginal('secret_access_key'));
        $this->assertSame($fingerprint, $destination->locatorFingerprint());
        $this->assertSame($job->id, $backup->backup_job_id);
    }

    public function test_new_records_default_to_local_and_network_destinations_have_no_owner(): void
    {
        $destination = $this->destination();
        $job = $this->job($destination);
        $this->assertSame(DockerHost::LOCAL_ID, $job->docker_host_id);
        $this->assertTrue($job->dockerHost->isLocal());
        $this->assertNull($destination->docker_host_id);
        $destination->update(['provider' => BackupDestination::PROVIDER_DOCKER_VOLUME]);
        $this->assertSame(DockerHost::LOCAL_ID, $destination->docker_host_id);
        $destination->update(['provider' => BackupDestination::PROVIDER_AWS_S3]);
        $this->assertNull($destination->docker_host_id);
    }

    public function test_host_deletion_cannot_remove_referenced_jobs_or_history(): void
    {
        $host = DockerHost::factory()->create();
        $job = $this->job($this->destination(), $host->id);
        $this->expectException(QueryException::class);

        try {
            $host->delete();
        } finally {
            $this->assertModelExists($job);
        }
    }

    public function test_rollback_refuses_to_reattribute_remote_resources_to_local(): void
    {
        $host = DockerHost::factory()->create();
        $job = $this->job($this->destination(), $host->id);
        $migration = require database_path('migrations/2026_09_17_101431_add_docker_host_attribution.php');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('remote resources or runs exist');

        try {
            $migration->down();
        } finally {
            $this->assertTrue(Schema::hasTable('docker_hosts'));
            $this->assertSame($host->id, $job->refresh()->docker_host_id);
        }
    }

    public function test_run_snapshot_keeps_its_host_when_the_job_moves(): void
    {
        $host = DockerHost::factory()->create(['agent_registered_at' => now(), 'agent_protocol_version' => 1, 'agent_capabilities' => ['inventory-v1', 'backup-v1', 'restore-v1']]);
        $job = $this->job($this->destination(), $host->id);
        $run = app(CreateBackupRunRecord::class)->handle($job, ['trigger' => 'manual', 'status' => 'queued', 'docker_host_id' => DockerHost::LOCAL_ID]);
        $job->update(['docker_host_id' => DockerHost::LOCAL_ID]);

        $run->refresh()->load('job.dockerHost');
        $this->assertSame($host->id, $run->docker_host_id);
        $this->assertSame($host->id, $run->executionJob()->docker_host_id);
        $this->assertTrue($run->executionJob()->dockerHost->is($host));
        $this->assertSame(DockerHost::LOCAL_ID, $job->refresh()->docker_host_id);
    }

    public function test_volume_names_are_unique_per_host_and_invalid_hosts_are_rejected(): void
    {
        $host = DockerHost::factory()->create();
        DockerVolume::create(['name' => 'shared']);
        DockerVolume::create(['name' => 'shared', 'docker_host_id' => $host->id]);
        $this->assertSame(2, DockerVolume::where('name', 'shared')->count());
        $this->expectException(QueryException::class);
        DockerVolume::create(['name' => 'shared', 'docker_host_id' => $host->id]);
    }

    public function test_unknown_host_cannot_be_used(): void
    {
        $this->expectException(QueryException::class);
        DockerVolume::create(['name' => 'shared', 'docker_host_id' => 999999]);
    }

    public function test_local_destination_fingerprint_distinguishes_remote_owners(): void
    {
        $destination = $this->destination();
        $destination->update(['provider' => BackupDestination::PROVIDER_LOCAL, 'settings' => ['archive_path' => '/backups']]);
        $localFingerprint = $destination->locatorFingerprint();
        $destination->update(['docker_host_id' => DockerHost::factory()->create()->id]);
        $this->assertNotSame($localFingerprint, $destination->locatorFingerprint());
    }

    #[DataProvider('localExecutors')]
    public function test_local_executors_refuse_remote_runs_before_side_effects(string $action, bool $isRestore): void
    {
        $host = DockerHost::factory()->create(['agent_registered_at' => now(), 'agent_protocol_version' => 1, 'agent_capabilities' => ['inventory-v1', 'backup-v1', 'restore-v1']]);
        $job = $this->job($this->destination(), $host->id);
        $run = $isRestore
            ? $this->restore($job, $host->id)
            : app(CreateBackupRunRecord::class)->handle($job, ['trigger' => 'manual', 'status' => 'queued']);
        $this->mock(DockerProcess::class)->shouldNotReceive('run', 'runWithInputFile');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires an agent');

        try {
            if ($action === RunRestoreContainer::class) {
                app($action)->handle($run, '/nonexistent/archive');
            } else {
                app($action)->handle($run);
            }
        } finally {
            $this->assertSame('queued', $run->refresh()->status);
            $this->assertNull($run->docker_container_id);
            $this->assertSame('active', $job->refresh()->status);
        }
    }

    public static function localExecutors(): array
    {
        return [
            [RunBackup::class, false],
            [RunBackupContainer::class, false],
            [RunRestore::class, true],
            [RunRestoreContainer::class, true],
        ];
    }

    public function test_remote_local_destination_cannot_be_read_through_the_orchestrator_filesystem(): void
    {
        $destination = $this->destination();
        $destination->update(['provider' => BackupDestination::PROVIDER_LOCAL, 'docker_host_id' => DockerHost::factory()->create()->id, 'settings' => ['archive_path' => '/backups']]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires an agent');
        app(DestinationStorage::class)->listBackupObjects($destination);
    }

    private function destination(): BackupDestination
    {
        return BackupDestination::create([
            'name' => 'S3', 'provider' => BackupDestination::PROVIDER_AWS_S3,
            'bucket' => 'backups', 'access_key_id' => 'access', 'secret_access_key' => 'secret',
        ]);
    }

    private function job(BackupDestination $destination, int $hostId = DockerHost::LOCAL_ID): BackupJob
    {
        return BackupJob::create([
            'docker_host_id' => $hostId, 'name' => 'Backup', 'volume_name' => 'shared',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY, 'status' => 'active',
        ]);
    }

    private function restore(BackupJob $job, int $hostId = DockerHost::LOCAL_ID): RestoreRun
    {
        return RestoreRun::create([
            'backup_job_id' => $job->id, 'backup_destination_id' => $job->backup_destination_id,
            'source_docker_host_id' => $hostId, 'target_docker_host_id' => $hostId,
            'source_volume_name' => 'shared', 'target_volume_name' => 'restored',
            'selected_backup_key' => 'backup.tar.gz', 'status' => 'queued', 'mode' => 'new_volume',
        ]);
    }
}
