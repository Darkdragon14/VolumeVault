<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
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
