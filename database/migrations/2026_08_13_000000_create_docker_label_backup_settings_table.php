<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('docker_label_backup_settings')) {
            Schema::create('docker_label_backup_settings', function (Blueprint $table): void {
                $table->id();
                $table->boolean('enabled')->default(false);
                $table->foreignId('backup_destination_id')->nullable()->constrained()->nullOnDelete();
                $table->json('defaults')->nullable();
                $table->text('last_sync_error')->nullable();
                $table->timestamp('last_synced_at')->nullable();
                $table->timestamps();
            });
        } else {
            if (! Schema::hasColumn('docker_label_backup_settings', 'id')) {
                throw new RuntimeException('Cannot recover docker_label_backup_settings without its primary key column.');
            }

            Schema::table('docker_label_backup_settings', function (Blueprint $table): void {
                if (! Schema::hasColumn('docker_label_backup_settings', 'enabled')) {
                    $table->boolean('enabled')->default(false);
                }
                if (! Schema::hasColumn('docker_label_backup_settings', 'backup_destination_id')) {
                    $table->unsignedBigInteger('backup_destination_id')->nullable();
                }
                if (! Schema::hasColumn('docker_label_backup_settings', 'defaults')) {
                    $table->json('defaults')->nullable();
                }
                if (! Schema::hasColumn('docker_label_backup_settings', 'last_sync_error')) {
                    $table->text('last_sync_error')->nullable();
                }
                if (! Schema::hasColumn('docker_label_backup_settings', 'last_synced_at')) {
                    $table->timestamp('last_synced_at')->nullable();
                }
                if (! Schema::hasColumn('docker_label_backup_settings', 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (! Schema::hasColumn('docker_label_backup_settings', 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        if (! $this->foreignKeyExists('backup_destination_id')) {
            Schema::table('docker_label_backup_settings', function (Blueprint $table): void {
                $table->foreign('backup_destination_id')
                    ->references('id')
                    ->on('backup_destinations')
                    ->nullOnDelete();
            });
        }

        DB::table('docker_label_backup_settings')->insertOrIgnore([
            'id' => 1,
            'enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('docker_label_backup_settings');
    }

    private function foreignKeyExists(string $column): bool
    {
        $foreignKeys = collect(Schema::getForeignKeys('docker_label_backup_settings'))
            ->filter(fn (array $foreignKey): bool => in_array($column, $foreignKey['columns'], true));
        $schema = match (DB::getDriverName()) {
            'pgsql' => DB::scalar('SELECT current_schema()'),
            'sqlite' => 'main',
            default => DB::getDatabaseName(),
        };

        foreach ($foreignKeys as $foreignKey) {
            if ($foreignKey['columns'] !== [$column]
                || $foreignKey['foreign_table'] !== 'backup_destinations'
                || $foreignKey['foreign_columns'] !== ['id']
                || ($foreignKey['foreign_schema'] ?? $schema) !== $schema
                || strtolower((string) $foreignKey['on_delete']) !== 'set null') {
                throw new RuntimeException("Cannot recover docker_label_backup_settings: malformed foreign key for {$column}.");
            }
        }

        return $foreignKeys->isNotEmpty();
    }
};
