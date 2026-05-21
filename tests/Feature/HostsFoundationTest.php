<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerVolume;
use App\Models\RestoreRun;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HostsFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_host_is_created_and_used_by_default_for_scoped_resources(): void
    {
        $localHost = DB::table('hosts')->where('type', 'local')->first();

        $this->assertNotNull($localHost);
        $this->assertSame('Local Docker Host', $localHost->name);
        $this->assertSame('online', $localHost->status);
        $this->assertSame(1, (int) $localHost->is_active);
        $this->assertSame(1, DB::table('hosts')->where('type', 'local')->count());

        DockerVolume::create(['name' => 'app_data', 'exists' => true]);
        $destination = $this->destination();
        $job = BackupJob::create([
            'name' => 'Nightly',
            'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);
        BackupRun::create([
            'backup_job_id' => $job->id,
            'trigger' => BackupRun::TRIGGER_MANUAL,
        ]);
        RestoreRun::create([
            'backup_job_id' => $job->id,
            'backup_destination_id' => $destination->id,
            'selected_backup_key' => 'backups/app_data.tar.gz',
            'source_volume_name' => 'app_data',
            'target_volume_name' => 'app_data_restored',
        ]);

        foreach (['docker_volumes', 'backup_jobs', 'backup_runs', 'restore_runs'] as $table) {
            $this->assertDatabaseHas($table, ['host_id' => $localHost->id]);
        }
    }

    public function test_host_id_columns_are_not_nullable(): void
    {
        foreach (['docker_volumes', 'backup_jobs', 'backup_runs', 'restore_runs'] as $table) {
            $hostId = collect(Schema::getColumns($table))->firstWhere('name', 'host_id');

            $this->assertNotNull($hostId);
            $this->assertFalse($hostId['nullable']);
            $this->assertNotNull($hostId['default']);
        }
    }

    public function test_docker_volume_names_are_unique_per_host(): void
    {
        $localHostId = DB::table('hosts')->where('type', 'local')->value('id');
        $agentHostId = $this->createAgentHost();

        DockerVolume::create(['name' => 'shared_data', 'exists' => true]);
        DB::table('docker_volumes')->insert([
            'host_id' => $agentHostId,
            'name' => 'shared_data',
            'exists' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(2, DB::table('docker_volumes')->where('name', 'shared_data')->count());
        $this->assertDatabaseHas('docker_volumes', ['host_id' => $localHostId, 'name' => 'shared_data']);
        $this->assertDatabaseHas('docker_volumes', ['host_id' => $agentHostId, 'name' => 'shared_data']);

        $this->expectException(QueryException::class);

        DockerVolume::create(['name' => 'shared_data', 'exists' => true]);
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

    private function createAgentHost(): int
    {
        $now = now();

        return (int) DB::table('hosts')->insertGetId([
            'name' => 'Remote Agent',
            'type' => 'agent',
            'status' => 'offline',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
