<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DatabaseMigrationRecoveryTest extends TestCase
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

    #[DataProvider('destinationMigrationStates')]
    public function test_destination_operation_migration_recovers_and_rolls_back(string $state): void
    {
        $migration = require database_path('migrations/2026_09_22_072732_add_destination_operations_to_agent_operations.php');
        $indexName = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            ? 'agent_operations_destination_action_status_index'
            : 'agent_operations_backup_destination_id_destination_action_status_index';

        if ($state !== 'complete') {
            $migration->down();
            if ($state !== 'fresh') {
                Schema::table('agent_operations', function (Blueprint $table) use ($state): void {
                    $table->foreignId('backup_destination_id')->nullable();
                    if ($state === 'foreign key') {
                        $table->foreign('backup_destination_id')->references('id')->on('backup_destinations')->nullOnDelete();
                    }
                    if ($state !== 'first column') {
                        $table->string('destination_action')->nullable();
                        $table->string('locator_fingerprint', 64)->nullable();
                        $table->text('result')->nullable();
                    }
                });
            }
        }

        $id = (string) \Illuminate\Support\Str::uuid();
        DB::table('agent_operations')->insert([
            'id' => $id,
            'docker_host_id' => \App\Models\DockerHost::factory()->create()->id,
            'kind' => 'destination',
            'payload' => 'preserved payload',
        ]);

        $migration->up();
        DB::table('agent_operations')->where('id', $id)->update(['result' => 'preserved result']);
        $migration->up();

        $indexes = collect(Schema::getIndexes('agent_operations'))->where('columns', ['backup_destination_id', 'destination_action', 'status']);
        $this->assertCount(1, $indexes);
        $this->assertSame(DB::getDriverName() === 'pgsql' ? substr($indexName, 0, 63) : $indexName, $indexes->first()['name']);
        $this->assertFalse($indexes->first()['unique']);
        $this->assertTrue($this->foreignKeyExists('agent_operations', 'backup_destination_id', 'backup_destinations', 'set null'));
        $this->assertDatabaseHas('agent_operations', ['id' => $id, 'payload' => 'preserved payload', 'result' => 'preserved result']);

        $migration->down();
        foreach (['backup_destination_id', 'destination_action', 'locator_fingerprint', 'result'] as $column) {
            $this->assertFalse(Schema::hasColumn('agent_operations', $column));
        }
        $this->assertDatabaseHas('agent_operations', ['id' => $id, 'payload' => 'preserved payload']);
        $migration->up();
        $this->assertTrue(Schema::hasIndex('agent_operations', ['backup_destination_id', 'destination_action', 'status']));
    }

    public static function destinationMigrationStates(): array
    {
        return [
            'fresh' => ['fresh'],
            'first column committed' => ['first column'],
            'all columns committed' => ['columns'],
            'foreign key committed before oversized index failure' => ['foreign key'],
            'already complete (legacy index on SQLite)' => ['complete'],
        ];
    }

    public function test_mysql_retries_the_original_failed_destination_migration_through_artisan(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requires nontransactional MySQL DDL and its identifier limit.');
        }

        $name = '2026_09_22_072732_add_destination_operations_to_agent_operations';
        (require database_path("migrations/{$name}.php"))->down();
        DB::table('migrations')->where('migration', $name)->delete();

        try {
            Schema::table('agent_operations', function (Blueprint $table): void {
                $table->foreignId('backup_destination_id')->nullable()->constrained()->nullOnDelete();
                $table->string('destination_action')->nullable();
                $table->string('locator_fingerprint', 64)->nullable();
                $table->text('result')->nullable();
                $table->index(['backup_destination_id', 'destination_action', 'status']);
            });
            $this->fail('The original oversized index should fail on MySQL.');
        } catch (QueryException $exception) {
            $this->assertSame(1059, $exception->errorInfo[1]);
        }

        $this->assertTrue(Schema::hasColumn('agent_operations', 'result'));
        $this->assertTrue($this->foreignKeyExists('agent_operations', 'backup_destination_id', 'backup_destinations', 'set null'));
        $this->assertFalse(Schema::hasIndex('agent_operations', ['backup_destination_id', 'destination_action', 'status']));

        $id = (string) \Illuminate\Support\Str::uuid();
        DB::table('agent_operations')->insert([
            'id' => $id,
            'docker_host_id' => \App\Models\DockerHost::factory()->create()->id,
            'kind' => 'destination',
            'result' => 'preserve across retry',
        ]);
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertDatabaseHas('migrations', ['migration' => $name]);
        $this->assertDatabaseHas('agent_operations', ['id' => $id, 'result' => 'preserve across retry']);
        $this->assertTrue(Schema::hasIndex('agent_operations', 'agent_operations_destination_action_status_index'));
    }

    public function test_migrations_repair_tables_missing_foreign_keys_indexes_and_checks(): void
    {
        Schema::dropIfExists('docker_label_backup_settings');
        Schema::create('docker_label_backup_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->unsignedBigInteger('backup_destination_id')->nullable();
            $table->json('defaults')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
        DB::table('docker_label_backup_settings')->insert([
            'id' => 2,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $settingsMigration = require database_path('migrations/2026_08_13_000000_create_docker_label_backup_settings_table.php');
        $settingsMigration->up();
        $settingsMigration->up();

        $this->assertTrue($this->foreignKeyExists('docker_label_backup_settings', 'backup_destination_id', 'backup_destinations', 'set null'));
        $this->assertDatabaseHas('docker_label_backup_settings', ['id' => 1]);
        $this->assertDatabaseHas('docker_label_backup_settings', ['id' => 2, 'enabled' => true]);
        $this->assertTrue(Schema::hasColumn('docker_label_backup_settings', 'last_sync_error'));

        Schema::dropIfExists('run_finalizations');
        Schema::create('run_finalizations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('backup_run_id')->nullable();
            $table->unsignedBigInteger('restore_run_id')->nullable();
            $table->unsignedBigInteger('backup_group_run_id')->nullable();
            $table->unsignedBigInteger('notification_channel_id')->nullable();
            $table->string('type');
            $table->string('deduplication_key');
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->uuid('claim_token')->nullable();
            $table->timestamp('enqueued_at')->nullable();
            $table->uuid('enqueue_token')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        $finalizationsMigration = require database_path('migrations/2026_08_28_010000_create_run_finalizations_table.php');
        $finalizationsMigration->up();
        $finalizationsMigration->up();

        foreach ([
            'run_finalizations_deduplication_key_unique',
            'run_finalizations_claim_token_unique',
            'run_finalizations_enqueue_token_unique',
            'run_finalizations_backup_owner_index',
            'run_finalizations_restore_owner_index',
            'run_finalizations_group_owner_index',
            'run_finalizations_status_available_at_index',
            'run_finalizations_status_claimed_at_index',
            'run_finalizations_status_enqueued_at_index',
        ] as $index) {
            $this->assertTrue(Schema::hasIndex('run_finalizations', $index), "Missing recovered index {$index}.");
        }

        $this->assertTrue($this->foreignKeyExists('run_finalizations', 'backup_run_id', 'backup_runs', 'cascade'));
        $this->assertTrue($this->foreignKeyExists('run_finalizations', 'restore_run_id', 'restore_runs', 'cascade'));
        $this->assertTrue($this->foreignKeyExists('run_finalizations', 'backup_group_run_id', 'backup_group_runs', 'cascade'));
        $this->assertTrue($this->foreignKeyExists('run_finalizations', 'notification_channel_id', 'notification_channels', 'set null'));
        $this->assertTrue(Schema::hasColumn('run_finalizations', 'context'));
        $this->assertTrue($this->ownerConstraintExists());
    }

    #[DataProvider('malformedIndexes')]
    public function test_recovery_rejects_malformed_named_indexes(string $name, array $columns, bool $unique): void
    {
        if ($name === 'run_finalizations_backup_owner_index') {
            Schema::table('run_finalizations', function (Blueprint $table): void {
                $table->index('backup_run_id', 'recovery_supporting_foreign_key_index');
            });
        }

        Schema::table('run_finalizations', function (Blueprint $table) use ($name, $columns, $unique): void {
            if (str_ends_with($name, '_unique')) {
                $table->dropUnique($name);
            } else {
                $table->dropIndex($name);
            }
            if ($unique) {
                $table->unique($columns, $name);
            } else {
                $table->index($columns, $name);
            }
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("malformed index {$name}");

        $this->finalizationsMigration()->up();
    }

    public static function malformedIndexes(): array
    {
        $fixtures = [];
        foreach (['deduplication_key', 'claim_token', 'enqueue_token'] as $column) {
            $name = "run_finalizations_{$column}_unique";
            $fixtures["{$column} nonunique"] = [$name, [$column], false];
            $fixtures["{$column} wrong column"] = [$name, ['status'], true];
        }
        $fixtures['owner wrong order'] = ['run_finalizations_backup_owner_index', ['type', 'backup_run_id', 'notification_channel_id'], false];
        $fixtures['polling wrong uniqueness'] = ['run_finalizations_status_available_at_index', ['status', 'available_at'], true];

        return $fixtures;
    }

    public function test_recovery_rejects_partial_or_prefix_unique_indexes(): void
    {
        Schema::table('run_finalizations', function (Blueprint $table): void {
            $table->dropUnique('run_finalizations_deduplication_key_unique');
        });
        $sql = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            ? 'CREATE UNIQUE INDEX run_finalizations_deduplication_key_unique ON run_finalizations (deduplication_key(10))'
            : "CREATE UNIQUE INDEX run_finalizations_deduplication_key_unique ON run_finalizations (deduplication_key) WHERE status = 'pending'";
        DB::statement($sql);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('malformed index run_finalizations_deduplication_key_unique');
        $this->finalizationsMigration()->up();
    }

    #[DataProvider('malformedForeignKeys')]
    public function test_recovery_rejects_malformed_foreign_keys(string $tableName, string $column, string $target, string $onDelete, string $fault): void
    {
        if ($fault === 'referenced column') {
            Schema::table($target, function (Blueprint $table): void {
                $table->unsignedBigInteger('recovery_alternate_id')->nullable()->unique();
            });
        }

        Schema::table($tableName, function (Blueprint $table) use ($column, $target, $onDelete, $fault): void {
            $table->dropForeign([$column]);
            $table->foreign($column)
                ->references($fault === 'referenced column' ? 'recovery_alternate_id' : 'id')
                ->on($fault === 'target' ? 'users' : $target)
                ->onDelete($fault === 'delete action' ? 'restrict' : $onDelete);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("malformed foreign key for {$column}");

        $migration = $tableName === 'run_finalizations'
            ? $this->finalizationsMigration()
            : require database_path('migrations/2026_08_13_000000_create_docker_label_backup_settings_table.php');
        $migration->up();
    }

    public static function malformedForeignKeys(): array
    {
        $fixtures = [];
        foreach ([
            ['docker_label_backup_settings', 'backup_destination_id', 'backup_destinations', 'set null'],
            ['run_finalizations', 'backup_run_id', 'backup_runs', 'cascade'],
            ['run_finalizations', 'restore_run_id', 'restore_runs', 'cascade'],
            ['run_finalizations', 'backup_group_run_id', 'backup_group_runs', 'cascade'],
            ['run_finalizations', 'notification_channel_id', 'notification_channels', 'set null'],
        ] as $definition) {
            foreach (['target', 'delete action', 'referenced column'] as $fault) {
                $fixtures["{$definition[0]} {$definition[1]} {$fault}"] = [...$definition, $fault];
            }
        }

        return $fixtures;
    }

    #[DataProvider('malformedOwnerChecks')]
    public function test_recovery_rejects_a_same_named_wrong_owner_check(string $expression): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite uses owner triggers.');
        }

        $this->dropOwnerConstraint();
        DB::statement("ALTER TABLE run_finalizations ADD CONSTRAINT run_finalizations_one_owner CHECK ({$expression})");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('malformed or unenforced exactly-one-owner constraint');
        $this->finalizationsMigration()->up();
    }

    public static function malformedOwnerChecks(): array
    {
        return [
            'unrelated column' => ['attempts >= 0'],
            'at least one owner' => ['(CASE WHEN backup_run_id IS NOT NULL THEN 1 ELSE 0 END + CASE WHEN restore_run_id IS NOT NULL THEN 1 ELSE 0 END + CASE WHEN backup_group_run_id IS NOT NULL THEN 1 ELSE 0 END) >= 1'],
            'missing owner' => ['(CASE WHEN backup_run_id IS NOT NULL THEN 1 ELSE 0 END + CASE WHEN restore_run_id IS NOT NULL THEN 1 ELSE 0 END) = 1'],
        ];
    }

    public function test_recovery_rejects_an_unenforced_or_unvalidated_owner_check(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            $this->markTestSkipped('Requires MySQL or PostgreSQL check enforcement metadata.');
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE run_finalizations ALTER CHECK run_finalizations_one_owner NOT ENFORCED');
        } else {
            $expression = DB::scalar("SELECT pg_get_expr(conbin, conrelid) FROM pg_constraint WHERE conrelid = 'run_finalizations'::regclass AND conname = 'run_finalizations_one_owner'");
            $this->dropOwnerConstraint();
            DB::statement("ALTER TABLE run_finalizations ADD CONSTRAINT run_finalizations_one_owner CHECK ({$expression}) NOT VALID");
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('malformed or unenforced exactly-one-owner constraint');
        $this->finalizationsMigration()->up();
    }

    #[DataProvider('ownerTriggerEvents')]
    public function test_sqlite_recovery_rejects_a_same_named_wrong_owner_trigger(string $event): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Requires SQLite owner triggers.');
        }

        DB::statement("DROP TRIGGER run_finalizations_one_owner_{$event}");
        DB::statement("CREATE TRIGGER run_finalizations_one_owner_{$event} BEFORE {$event} ON run_finalizations BEGIN SELECT 1; END");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("malformed owner trigger run_finalizations_one_owner_{$event}");
        $this->finalizationsMigration()->up();
    }

    public static function ownerTriggerEvents(): array
    {
        return [['insert'], ['update']];
    }

    public function test_recovered_owner_guard_enforces_all_owner_combinations_on_insert_and_update(): void
    {
        $this->dropOwnerConstraint();
        $this->finalizationsMigration()->up();
        $this->finalizationsMigration()->up();

        $groupId = DB::table('backup_job_groups')->insertGetId([
            'name' => 'Recovery group', 'schedule_type' => 'daily', 'cron_expression' => '0 2 * * *', 'status' => 'active',
        ]);
        $runId = DB::table('backup_group_runs')->insertGetId([
            'backup_job_group_id' => $groupId, 'status' => 'queued', 'trigger' => 'manual',
        ]);
        $destinationId = DB::table('backup_destinations')->insertGetId([
            'name' => 'Recovery destination', 'provider' => 'local', 'bucket' => 'local', 'access_key_id' => '', 'secret_access_key' => '',
        ]);
        $jobId = DB::table('backup_jobs')->insertGetId([
            'name' => 'Recovery job', 'volume_name' => 'recovery', 'backup_destination_id' => $destinationId, 'schedule_type' => 'daily',
        ]);
        $backupRunId = DB::table('backup_runs')->insertGetId(['backup_job_id' => $jobId, 'trigger' => 'manual']);
        $restoreRunId = DB::table('restore_runs')->insertGetId([
            'backup_job_id' => $jobId, 'selected_backup_key' => 'backup.tar.gz', 'source_volume_name' => 'recovery', 'target_volume_name' => 'restored',
        ]);
        $id = DB::table('run_finalizations')->insertGetId([
            'backup_group_run_id' => $runId, 'type' => 'test', 'deduplication_key' => 'valid-owner',
        ]);

        for ($mask = 0; $mask < 8; $mask++) {
            $owners = [
                'backup_run_id' => ($mask & 1) ? $backupRunId : null,
                'restore_run_id' => ($mask & 2) ? $restoreRunId : null,
                'backup_group_run_id' => ($mask & 4) ? $runId : null,
            ];
            $valid = count(array_filter($owners)) === 1;

            foreach (['insert', 'update'] as $operation) {
                try {
                    if ($operation === 'insert') {
                        DB::table('run_finalizations')->insert($owners + ['type' => 'test', 'deduplication_key' => "owners-{$mask}"]);
                    } else {
                        DB::table('run_finalizations')->where('id', $id)->update($owners);
                    }
                    $this->assertTrue($valid, "Invalid {$operation} accepted for owner mask {$mask}.");
                } catch (QueryException $exception) {
                    $this->assertFalse($valid, $exception->getMessage());
                    $this->assertStringContainsString('run_finalizations', $exception->getMessage());
                }
            }
        }
    }

    public function test_sqlite_recovery_rejects_existing_ownerless_rows(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite triggers need an explicit existing-row validation.');
        }

        $this->dropOwnerConstraint();
        DB::table('run_finalizations')->insert(['type' => 'test', 'deduplication_key' => 'ownerless']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('existing rows must have exactly one owner');
        $this->finalizationsMigration()->up();
    }

    private function finalizationsMigration(): \Illuminate\Database\Migrations\Migration
    {
        return require database_path('migrations/2026_08_28_010000_create_run_finalizations_table.php');
    }

    private function dropOwnerConstraint(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER run_finalizations_one_owner_insert');
            DB::statement('DROP TRIGGER run_finalizations_one_owner_update');
        } else {
            $command = DB::getDriverName() === 'mysql' ? 'DROP CHECK' : 'DROP CONSTRAINT';
            DB::statement("ALTER TABLE run_finalizations {$command} run_finalizations_one_owner");
        }
    }

    public function test_postgresql_recovers_owner_constraint_despite_same_named_constraint_in_another_schema(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This cross-schema recovery test requires PostgreSQL.');
        }

        DB::statement('CREATE SCHEMA migration_recovery_decoy');

        try {
            DB::statement('CREATE TABLE migration_recovery_decoy.run_finalizations (id bigint CONSTRAINT run_finalizations_one_owner CHECK (id > 0))');
            DB::statement('ALTER TABLE run_finalizations DROP CONSTRAINT run_finalizations_one_owner');

            $this->assertTrue(DB::table('information_schema.table_constraints')
                ->where('constraint_schema', 'migration_recovery_decoy')
                ->where('table_name', 'run_finalizations')
                ->where('constraint_name', 'run_finalizations_one_owner')
                ->where('constraint_type', 'CHECK')
                ->exists());
            $this->assertFalse($this->ownerConstraintExists());

            $migration = require database_path('migrations/2026_08_28_010000_create_run_finalizations_table.php');
            $migration->up();
            $migration->up();

            $this->assertTrue($this->ownerConstraintExists());
        } finally {
            DB::statement('DROP SCHEMA migration_recovery_decoy CASCADE');
        }
    }

    private function ownerConstraintExists(): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return DB::table('sqlite_master')->where('type', 'trigger')
                ->whereIn('name', ['run_finalizations_one_owner_insert', 'run_finalizations_one_owner_update'])->count() === 2;
        }

        $constraints = DB::table('information_schema.table_constraints')
            ->where('table_name', 'run_finalizations')
            ->where('constraint_name', 'run_finalizations_one_owner')
            ->where('constraint_type', 'CHECK');

        return match (DB::getDriverName()) {
            'mysql', 'mariadb' => $constraints->where('constraint_schema', DB::getDatabaseName())->exists(),
            'pgsql' => $constraints->whereRaw('constraint_schema = current_schema()')->exists(),
            default => false,
        };
    }

    private function foreignKeyExists(string $table, string $column, string $foreignTable, string $onDelete): bool
    {
        return collect(Schema::getForeignKeys($table))->contains(
            fn (array $foreignKey): bool => $foreignKey['columns'] === [$column]
                && $foreignKey['foreign_table'] === $foreignTable
                && $foreignKey['foreign_columns'] === ['id']
                && strtolower((string) $foreignKey['on_delete']) === $onDelete,
        );
    }
}
