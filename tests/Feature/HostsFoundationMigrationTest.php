<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HostsFoundationMigrationTest extends TestCase
{
    public function test_hosts_foundation_migration_backfills_legacy_rows(): void
    {
        $connection = 'hosts_foundation_migration_test';
        $databasePath = tempnam(sys_get_temp_dir(), 'volumevault_hosts_');
        $originalDefaultConnection = config('database.default');
        $originalConnectionConfig = config("database.connections.{$connection}");

        config()->set("database.connections.{$connection}", [
            'driver' => 'sqlite',
            'database' => $databasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        config()->set('database.default', $connection);
        DB::setDefaultConnection($connection);
        DB::purge($connection);
        DB::reconnect($connection);

        try {
            $this->createLegacySchema();
            $this->insertLegacyRows();

            $migration = require database_path('migrations/2026_05_21_000000_add_hosts_foundation.php');
            $migration->up();

            $localHostId = DB::table('hosts')->where('type', 'local')->value('id');

            $this->assertNotNull($localHostId);
            $this->assertSame(1, DB::table('hosts')->where('type', 'local')->count());
            $this->assertSame(1, DB::table('docker_volumes')->where('host_id', $localHostId)->count());
            $this->assertSame(1, DB::table('backup_jobs')->where('host_id', $localHostId)->count());
            $this->assertSame(1, DB::table('backup_runs')->where('host_id', $localHostId)->count());
            $this->assertSame(1, DB::table('restore_runs')->where('host_id', $localHostId)->count());
            $this->assertFalse(Schema::hasIndex('docker_volumes', ['name'], 'unique'));
            $this->assertTrue(Schema::hasIndex('docker_volumes', ['host_id', 'name'], 'unique'));

            Schema::create('agent_commands', function (Blueprint $table) {
                $table->id();
                $table->foreignId('host_id');
                $table->timestamp('lease_until')->nullable();
            });

            $authenticationMigration = require database_path('migrations/2026_07_15_135920_add_agent_authentication_columns.php');
            $authenticationMigration->up();

            $this->assertTrue(Schema::hasColumn('hosts', 'agent_token_hash'));
            $this->assertTrue(Schema::hasColumn('agent_commands', 'lease_token_hash'));

            $authenticationMigration->down();
            $this->assertFalse(Schema::hasColumn('hosts', 'agent_token_hash'));
            $this->assertFalse(Schema::hasColumn('agent_commands', 'lease_token_hash'));
            $authenticationMigration->up();

            $agentHostId = DB::table('hosts')->insertGetId([
                'name' => 'Remote Agent',
                'type' => 'agent',
                'status' => 'offline',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('docker_volumes')->insert([
                'host_id' => $agentHostId,
                'name' => 'legacy_data',
                'exists' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->expectException(QueryException::class);

            DB::table('docker_volumes')->insert([
                'host_id' => $localHostId,
                'name' => 'legacy_data',
                'exists' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } finally {
            DB::disconnect($connection);
            DB::setDefaultConnection($originalDefaultConnection);
            config()->set('database.default', $originalDefaultConnection);
            config()->set("database.connections.{$connection}", $originalConnectionConfig);

            if (is_string($databasePath) && file_exists($databasePath)) {
                unlink($databasePath);
            }
        }
    }

    private function createLegacySchema(): void
    {
        Schema::create('docker_volumes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('exists')->default(true);
            $table->timestamps();
        });

        Schema::create('backup_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('volume_name')->index();
            $table->unsignedBigInteger('backup_destination_id');
            $table->string('schedule_type');
            $table->string('status')->default('active')->index();
            $table->timestamps();
        });

        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('backup_job_id');
            $table->string('status')->default('queued')->index();
            $table->string('trigger');
            $table->timestamps();
        });

        Schema::create('restore_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('backup_job_id');
            $table->unsignedBigInteger('backup_destination_id')->nullable();
            $table->string('selected_backup_key');
            $table->string('source_volume_name');
            $table->string('target_volume_name');
            $table->string('status')->default('queued')->index();
            $table->timestamps();
        });
    }

    private function insertLegacyRows(): void
    {
        $now = now();

        DB::table('docker_volumes')->insert([
            'name' => 'legacy_data',
            'exists' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $backupJobId = DB::table('backup_jobs')->insertGetId([
            'name' => 'Legacy Nightly',
            'volume_name' => 'legacy_data',
            'backup_destination_id' => 1,
            'schedule_type' => 'daily',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('backup_runs')->insert([
            'backup_job_id' => $backupJobId,
            'status' => 'queued',
            'trigger' => 'manual',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('restore_runs')->insert([
            'backup_job_id' => $backupJobId,
            'backup_destination_id' => 1,
            'selected_backup_key' => 'backups/legacy_data.tar.gz',
            'source_volume_name' => 'legacy_data',
            'target_volume_name' => 'legacy_data_restored',
            'status' => 'queued',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
