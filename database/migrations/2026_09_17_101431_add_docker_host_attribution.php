<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $this->alterSchema(fn () => $this->addHostAttribution());
    }

    public function down(): void
    {
        $this->alterSchema(fn () => $this->removeHostAttribution());
    }

    private function alterSchema(callable $operation): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $operation();

            return;
        }

        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Docker host attribution must be migrated outside an existing SQLite transaction.');
        }

        // SQLite table rebuilds must disable cascades before entering a transaction.
        Schema::withoutForeignKeyConstraints(function () use ($operation): void {
            DB::transaction(function () use ($operation): void {
                $operation();

                if (DB::select('PRAGMA foreign_key_check') !== []) {
                    throw new RuntimeException('Docker host attribution failed foreign key validation.');
                }
            });
        });
    }

    private function addHostAttribution(): void
    {
        Schema::create('docker_hosts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('driver')->default('agent');
            $table->timestamps();
        });

        DB::table('docker_hosts')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'name' => 'Local',
            'driver' => 'local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['docker_volumes', 'backup_jobs', 'backup_runs'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('docker_host_id')->default(1)->constrained()->restrictOnDelete();
            });
        }

        Schema::table('restore_runs', function (Blueprint $table): void {
            $table->foreignId('source_docker_host_id')->default(1)->constrained('docker_hosts')->restrictOnDelete();
            $table->foreignId('target_docker_host_id')->default(1)->constrained('docker_hosts')->restrictOnDelete();
            $table->index(['target_docker_host_id', 'target_volume_name']);
        });

        Schema::table('backup_destinations', function (Blueprint $table): void {
            $table->foreignId('docker_host_id')->nullable()->constrained()->restrictOnDelete();
        });

        DB::table('backup_destinations')->whereIn('provider', ['local', 'docker_volume'])->update(['docker_host_id' => 1]);

        Schema::table('docker_volumes', function (Blueprint $table): void {
            $table->dropUnique(['name']);
            $table->unique(['docker_host_id', 'name']);
        });

        Schema::table('backup_jobs', function (Blueprint $table): void {
            $table->dropUnique(['configuration_key']);
            $table->unique(['docker_host_id', 'configuration_key']);
            $table->index(['docker_host_id', 'volume_name']);
        });

        Schema::table('backup_runs', function (Blueprint $table): void {
            $table->index(['docker_host_id', 'source_volume_name']);
        });
    }

    private function removeHostAttribution(): void
    {
        foreach ([
            'docker_volumes' => ['docker_host_id'],
            'backup_jobs' => ['docker_host_id'],
            'backup_runs' => ['docker_host_id'],
            'restore_runs' => ['source_docker_host_id', 'target_docker_host_id'],
            'backup_destinations' => ['docker_host_id'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                if (DB::table($table)->where($column, '!=', 1)->exists()) {
                    throw new RuntimeException('Cannot remove Docker host attribution while remote resources or runs exist.');
                }
            }
        }

        if (DB::table('docker_volumes')->select('name')->groupBy('name')->havingRaw('COUNT(*) > 1')->exists()
            || DB::table('backup_jobs')->whereNotNull('configuration_key')->select('configuration_key')->groupBy('configuration_key')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot remove Docker host attribution while host-scoped names are duplicated.');
        }

        Schema::table('backup_runs', function (Blueprint $table): void {
            $table->dropIndex(['docker_host_id', 'source_volume_name']);
            $table->dropConstrainedForeignId('docker_host_id');
        });

        Schema::table('backup_jobs', function (Blueprint $table): void {
            $table->dropUnique(['docker_host_id', 'configuration_key']);
            $table->dropIndex(['docker_host_id', 'volume_name']);
            $table->dropConstrainedForeignId('docker_host_id');
            $table->unique('configuration_key');
        });

        Schema::table('docker_volumes', function (Blueprint $table): void {
            $table->dropUnique(['docker_host_id', 'name']);
            $table->dropConstrainedForeignId('docker_host_id');
            $table->unique('name');
        });

        Schema::table('restore_runs', function (Blueprint $table): void {
            $table->dropIndex(['target_docker_host_id', 'target_volume_name']);
            $table->dropConstrainedForeignId('source_docker_host_id');
            $table->dropConstrainedForeignId('target_docker_host_id');
        });

        Schema::table('backup_destinations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('docker_host_id');
        });

        Schema::dropIfExists('docker_hosts');
    }
};
