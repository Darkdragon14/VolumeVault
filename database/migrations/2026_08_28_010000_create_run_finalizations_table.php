<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('run_finalizations')) {
            Schema::create('run_finalizations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('backup_run_id')->nullable()->constrained()->cascadeOnDelete();
                $table->foreignId('restore_run_id')->nullable()->constrained()->cascadeOnDelete();
                $table->foreignId('backup_group_run_id')->nullable()->constrained()->cascadeOnDelete();
                $table->foreignId('notification_channel_id')->nullable()->constrained()->nullOnDelete();
                $table->string('type');
                $table->string('deduplication_key')->unique();
                $table->string('status')->default('pending');
                $table->unsignedInteger('attempts')->default(0);
                $table->timestamp('available_at')->nullable();
                $table->timestamp('claimed_at')->nullable();
                $table->uuid('claim_token')->nullable()->unique();
                $table->timestamp('enqueued_at')->nullable();
                $table->uuid('enqueue_token')->nullable()->unique();
                $table->timestamp('finished_at')->nullable();
                $table->text('last_error')->nullable();
                $table->json('context')->nullable();
                $table->timestamps();

                $table->index(['backup_run_id', 'type', 'notification_channel_id'], 'run_finalizations_backup_owner_index');
                $table->index(['restore_run_id', 'type', 'notification_channel_id'], 'run_finalizations_restore_owner_index');
                $table->index(['backup_group_run_id', 'type', 'notification_channel_id'], 'run_finalizations_group_owner_index');
                $table->index(['status', 'available_at']);
                $table->index(['status', 'claimed_at']);
                $table->index(['status', 'enqueued_at']);
            });
        } else {
            $this->ensureColumns();
        }

        $this->ensureIndexes();
        $this->ensureForeignKeys();
        $this->enforceExactlyOneOwner();
    }

    public function down(): void
    {
        Schema::dropIfExists('run_finalizations');
    }

    private function enforceExactlyOneOwner(): void
    {
        $expression = '(CASE WHEN backup_run_id IS NOT NULL THEN 1 ELSE 0 END + CASE WHEN restore_run_id IS NOT NULL THEN 1 ELSE 0 END + CASE WHEN backup_group_run_id IS NOT NULL THEN 1 ELSE 0 END) = 1';

        if (DB::getDriverName() === 'sqlite') {
            $sqliteExpression = str_replace(
                ['backup_run_id', 'restore_run_id', 'backup_group_run_id'],
                ['NEW.backup_run_id', 'NEW.restore_run_id', 'NEW.backup_group_run_id'],
                $expression,
            );
            foreach (['insert', 'update'] as $event) {
                $name = "run_finalizations_one_owner_{$event}";
                $sql = "CREATE TRIGGER {$name} BEFORE {$event} ON run_finalizations WHEN NOT ({$sqliteExpression}) BEGIN SELECT RAISE(ABORT, 'run_finalizations must have exactly one owner'); END";
                $existing = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->value('sql');

                if ($existing !== null && $this->normalizeDefinition($existing) !== $this->normalizeDefinition($sql)) {
                    throw new RuntimeException("Cannot recover run_finalizations: malformed owner trigger {$name}.");
                }

                if ($existing === null) {
                    DB::statement($sql);
                }
            }

            if (DB::table('run_finalizations')->whereRaw("NOT ({$expression})")->exists()) {
                throw new RuntimeException('Cannot recover run_finalizations: existing rows must have exactly one owner.');
            }

            return;
        }

        if ($this->ownerConstraintExists($expression)) {
            return;
        }

        DB::statement("ALTER TABLE run_finalizations ADD CONSTRAINT run_finalizations_one_owner CHECK ({$expression})");
    }

    private function ensureColumns(): void
    {
        if (! Schema::hasColumn('run_finalizations', 'id')) {
            throw new RuntimeException('Cannot recover run_finalizations without its primary key column.');
        }

        $identityColumns = ['backup_run_id', 'restore_run_id', 'backup_group_run_id', 'type', 'deduplication_key'];
        $missingIdentityColumns = collect($identityColumns)
            ->filter(fn (string $column): bool => ! Schema::hasColumn('run_finalizations', $column));

        if ($missingIdentityColumns->isNotEmpty() && DB::table('run_finalizations')->exists()) {
            throw new RuntimeException(
                'Cannot safely recover populated run_finalizations with missing identity columns: '.$missingIdentityColumns->implode(', ').'.',
            );
        }

        Schema::table('run_finalizations', function (Blueprint $table): void {
            if (! Schema::hasColumn('run_finalizations', 'backup_run_id')) {
                $table->unsignedBigInteger('backup_run_id')->nullable();
            }
            if (! Schema::hasColumn('run_finalizations', 'restore_run_id')) {
                $table->unsignedBigInteger('restore_run_id')->nullable();
            }
            if (! Schema::hasColumn('run_finalizations', 'backup_group_run_id')) {
                $table->unsignedBigInteger('backup_group_run_id')->nullable();
            }
            if (! Schema::hasColumn('run_finalizations', 'notification_channel_id')) {
                $table->unsignedBigInteger('notification_channel_id')->nullable();
            }
            if (! Schema::hasColumn('run_finalizations', 'type')) {
                $table->string('type');
            }
            if (! Schema::hasColumn('run_finalizations', 'deduplication_key')) {
                $table->string('deduplication_key');
            }
            if (! Schema::hasColumn('run_finalizations', 'status')) {
                $table->string('status')->default('pending');
            }
            if (! Schema::hasColumn('run_finalizations', 'attempts')) {
                $table->unsignedInteger('attempts')->default(0);
            }
            if (! Schema::hasColumn('run_finalizations', 'available_at')) {
                $table->timestamp('available_at')->nullable();
            }
            if (! Schema::hasColumn('run_finalizations', 'claimed_at')) {
                $table->timestamp('claimed_at')->nullable();
            }
            if (! Schema::hasColumn('run_finalizations', 'claim_token')) {
                $table->uuid('claim_token')->nullable();
            }
            if (! Schema::hasColumn('run_finalizations', 'enqueued_at')) {
                $table->timestamp('enqueued_at')->nullable();
            }
            if (! Schema::hasColumn('run_finalizations', 'enqueue_token')) {
                $table->uuid('enqueue_token')->nullable();
            }
            if (! Schema::hasColumn('run_finalizations', 'finished_at')) {
                $table->timestamp('finished_at')->nullable();
            }
            if (! Schema::hasColumn('run_finalizations', 'last_error')) {
                $table->text('last_error')->nullable();
            }
            if (! Schema::hasColumn('run_finalizations', 'context')) {
                $table->json('context')->nullable();
            }
            if (! Schema::hasColumn('run_finalizations', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (! Schema::hasColumn('run_finalizations', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    private function ensureIndexes(): void
    {
        $indexes = [
            'run_finalizations_deduplication_key_unique' => ['columns' => ['deduplication_key'], 'unique' => true],
            'run_finalizations_claim_token_unique' => ['columns' => ['claim_token'], 'unique' => true],
            'run_finalizations_enqueue_token_unique' => ['columns' => ['enqueue_token'], 'unique' => true],
            'run_finalizations_backup_owner_index' => ['columns' => ['backup_run_id', 'type', 'notification_channel_id'], 'unique' => false],
            'run_finalizations_restore_owner_index' => ['columns' => ['restore_run_id', 'type', 'notification_channel_id'], 'unique' => false],
            'run_finalizations_group_owner_index' => ['columns' => ['backup_group_run_id', 'type', 'notification_channel_id'], 'unique' => false],
            'run_finalizations_status_available_at_index' => ['columns' => ['status', 'available_at'], 'unique' => false],
            'run_finalizations_status_claimed_at_index' => ['columns' => ['status', 'claimed_at'], 'unique' => false],
            'run_finalizations_status_enqueued_at_index' => ['columns' => ['status', 'enqueued_at'], 'unique' => false],
        ];

        $existingIndexes = collect(Schema::getIndexes('run_finalizations'))->keyBy('name');

        foreach ($indexes as $name => $definition) {
            if ($existing = $existingIndexes->get($name)) {
                if ($existing['columns'] !== $definition['columns']
                    || $existing['unique'] !== $definition['unique']
                    || ! $this->indexHasFullCoverage($name)) {
                    throw new RuntimeException("Cannot recover run_finalizations: malformed index {$name}.");
                }

                continue;
            }

            Schema::table('run_finalizations', function (Blueprint $table) use ($name, $definition): void {
                if ($definition['unique']) {
                    $table->unique($definition['columns'], $name);
                } else {
                    $table->index($definition['columns'], $name);
                }
            });
        }
    }

    private function indexHasFullCoverage(string $name): bool
    {
        return match (DB::getDriverName()) {
            'pgsql' => (bool) DB::scalar(
                "SELECT indisvalid AND indisready AND indpred IS NULL AND indexprs IS NULL FROM pg_index JOIN pg_class ON pg_class.oid = indexrelid WHERE indrelid = 'run_finalizations'::regclass AND pg_class.relname = ?",
                [$name],
            ),
            'sqlite' => DB::scalar("SELECT partial FROM pragma_index_list('run_finalizations') WHERE name = ?", [$name]) === 0,
            'mysql', 'mariadb' => ! DB::table('information_schema.statistics')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', 'run_finalizations')
                ->where('index_name', $name)
                ->whereNotNull('sub_part')
                ->exists(),
            default => false,
        };
    }

    private function ensureForeignKeys(): void
    {
        $foreignKeys = [
            'backup_run_id' => ['table' => 'backup_runs', 'on_delete' => 'cascade'],
            'restore_run_id' => ['table' => 'restore_runs', 'on_delete' => 'cascade'],
            'backup_group_run_id' => ['table' => 'backup_group_runs', 'on_delete' => 'cascade'],
            'notification_channel_id' => ['table' => 'notification_channels', 'on_delete' => 'set null'],
        ];

        foreach ($foreignKeys as $column => $definition) {
            if ($this->foreignKeyExists($column, $definition['table'], $definition['on_delete'])) {
                continue;
            }

            Schema::table('run_finalizations', function (Blueprint $table) use ($column, $definition): void {
                $foreignKey = $table->foreign($column)
                    ->references('id')
                    ->on($definition['table']);

                if ($definition['on_delete'] === 'cascade') {
                    $foreignKey->cascadeOnDelete();
                } else {
                    $foreignKey->nullOnDelete();
                }
            });
        }
    }

    private function foreignKeyExists(string $column, string $table, string $onDelete): bool
    {
        $foreignKeys = collect(Schema::getForeignKeys('run_finalizations'))
            ->filter(fn (array $foreignKey): bool => in_array($column, $foreignKey['columns'], true));
        $schema = match (DB::getDriverName()) {
            'pgsql' => DB::scalar('SELECT current_schema()'),
            'sqlite' => 'main',
            default => DB::getDatabaseName(),
        };

        foreach ($foreignKeys as $foreignKey) {
            if ($foreignKey['columns'] !== [$column]
                || $foreignKey['foreign_table'] !== $table
                || $foreignKey['foreign_columns'] !== ['id']
                || ($foreignKey['foreign_schema'] ?? $schema) !== $schema
                || strtolower((string) $foreignKey['on_delete']) !== $onDelete) {
                throw new RuntimeException("Cannot recover run_finalizations: malformed foreign key for {$column}.");
            }
        }

        return $foreignKeys->isNotEmpty();
    }

    private function ownerConstraintExists(string $expression): bool
    {
        $constraint = match (DB::getDriverName()) {
            'mysql', 'mariadb' => DB::table('information_schema.table_constraints as tc')
                ->leftJoin('information_schema.check_constraints as cc', function ($join): void {
                    $join->on('tc.constraint_schema', '=', 'cc.constraint_schema')
                        ->on('tc.constraint_name', '=', 'cc.constraint_name');
                })
                ->where('tc.constraint_schema', DB::getDatabaseName())
                ->where('tc.table_name', 'run_finalizations')
                ->where('tc.constraint_name', 'run_finalizations_one_owner')
                ->selectRaw('cc.check_clause as definition, tc.constraint_type as type')
                ->selectRaw(DB::getDriverName() === 'mysql' ? "tc.enforced = 'YES' as enforced" : '1 as enforced')
                ->first(),
            'pgsql' => DB::table('pg_constraint')
                ->join('pg_class', 'pg_constraint.conrelid', '=', 'pg_class.oid')
                ->join('pg_namespace', 'pg_class.relnamespace', '=', 'pg_namespace.oid')
                ->where('conname', 'run_finalizations_one_owner')
                ->where('pg_class.relname', 'run_finalizations')
                ->whereRaw('pg_namespace.nspname = current_schema()')
                ->selectRaw('pg_get_expr(conbin, conrelid) as definition, contype as type, convalidated as enforced')
                ->first(),
            default => throw new RuntimeException('Unsupported database for run_finalizations owner constraint recovery.'),
        };

        if ($constraint === null) {
            return false;
        }

        $cases = array_map(
            fn (string $column): string => "CASE WHEN ({$column} IS NOT NULL) THEN 1 ELSE 0 END",
            ['backup_run_id', 'restore_run_id', 'backup_group_run_id'],
        );
        $expected = match (DB::getDriverName()) {
            'mysql' => "(((({$cases[0]}) + ({$cases[1]})) + ({$cases[2]})) = 1)",
            'pgsql' => "((({$cases[0]} + {$cases[1]}) + {$cases[2]}) = 1)",
            default => $expression,
        };

        if (! in_array($constraint->type, ['CHECK', 'c'], true)
            || ! $constraint->enforced
            || $this->normalizeDefinition((string) $constraint->definition) !== $this->normalizeDefinition($expected)) {
            throw new RuntimeException('Cannot recover run_finalizations: malformed or unenforced exactly-one-owner constraint.');
        }

        return true;
    }

    private function normalizeDefinition(string $definition): string
    {
        $definition = str_replace(
            ['`backup_run_id`', '`restore_run_id`', '`backup_group_run_id`'],
            ['backup_run_id', 'restore_run_id', 'backup_group_run_id'],
            $definition,
        );

        return strtolower(preg_replace('/\s*([()+=;,])\s*/', '$1', preg_replace('/\s+/', ' ', trim($definition))));
    }
};
