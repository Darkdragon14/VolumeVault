<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InitialSchemaMigrationTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databasePath = storage_path('framework/testing/legacy-schema.sqlite');
        $databaseDirectory = dirname($this->databasePath);

        if (! is_dir($databaseDirectory)) {
            mkdir($databaseDirectory, 0755, true);
        }

        if (file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        touch($this->databasePath);

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databasePath,
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');

        if (isset($this->databasePath) && file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        parent::tearDown();
    }

    public function test_initial_migration_allows_complete_legacy_schema_without_agent_tables(): void
    {
        foreach ($this->legacyTables() as $table) {
            Schema::create($table, function (Blueprint $blueprint): void {
                $blueprint->id();
            });
        }

        $migration = require database_path('migrations/0001_01_01_000000_create_volumevault_schema.php');

        $migration->up();

        $this->assertFalse(Schema::hasTable('hosts'));
        $this->assertFalse(Schema::hasTable('host_user'));
        $this->assertFalse(Schema::hasTable('agent_commands'));
    }

    public function test_hosts_foundation_rollback_refuses_duplicate_volume_names_without_partial_changes(): void
    {
        Schema::create('hosts', function (Blueprint $table): void {
            $table->id();
        });

        foreach (['docker_volumes', 'backup_jobs', 'backup_runs', 'restore_runs'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) use ($tableName): void {
                $table->id();
                $table->foreignId('host_id');

                if ($tableName === 'docker_volumes') {
                    $table->string('name');
                    $table->unique(['host_id', 'name']);
                }
            });
        }

        DB::table('hosts')->insert([['id' => 1], ['id' => 2]]);
        DB::table('docker_volumes')->insert([
            ['host_id' => 1, 'name' => 'app-data'],
            ['host_id' => 2, 'name' => 'app-data'],
        ]);

        $migration = require database_path('migrations/2026_05_21_000000_add_hosts_foundation.php');

        try {
            $migration->down();
            $this->fail('Rollback should refuse duplicate legacy volume names.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Cannot roll back host support while Docker volume names are duplicated across hosts.', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('docker_volumes', 'host_id'));
        $this->assertSame(2, DB::table('docker_volumes')->where('name', 'app-data')->count());
        $this->assertTrue(Schema::hasIndex('docker_volumes', ['host_id', 'name'], 'unique'));
    }

    public function test_hosts_foundation_rollback_restores_legacy_volume_name_uniqueness(): void
    {
        Schema::create('hosts', function (Blueprint $table): void {
            $table->id();
        });

        foreach (['docker_volumes', 'backup_jobs', 'backup_runs', 'restore_runs'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) use ($tableName): void {
                $table->id();
                $table->foreignId('host_id');

                if ($tableName === 'docker_volumes') {
                    $table->string('name');
                    $table->unique(['host_id', 'name']);
                }
            });
        }

        DB::table('hosts')->insert([['id' => 1], ['id' => 2]]);
        DB::table('docker_volumes')->insert([
            ['host_id' => 1, 'name' => 'app-data'],
            ['host_id' => 2, 'name' => 'other-data'],
        ]);

        $migration = require database_path('migrations/2026_05_21_000000_add_hosts_foundation.php');
        $migration->down();

        $this->assertFalse(Schema::hasColumn('docker_volumes', 'host_id'));
        $this->assertTrue(Schema::hasIndex('docker_volumes', ['name'], 'unique'));

        $this->expectException(QueryException::class);
        DB::table('docker_volumes')->insert(['name' => 'app-data']);
    }

    /**
     * @return list<string>
     */
    private function legacyTables(): array
    {
        return [
            'users',
            'password_reset_tokens',
            'sessions',
            'cache',
            'cache_locks',
            'jobs',
            'job_batches',
            'failed_jobs',
            'docker_volumes',
            'backup_destinations',
            'backup_jobs',
            'backup_runs',
            'restore_runs',
            'activity_logs',
            'notification_channels',
            'backup_job_notification_channel',
            'personal_access_tokens',
        ];
    }
}
