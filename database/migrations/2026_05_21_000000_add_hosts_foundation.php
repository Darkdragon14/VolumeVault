<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LOCAL_HOST_STATUS = 'online';

    private const LOCAL_HOST_TYPE = 'local';

    public function up(): void
    {
        $this->createHostsTable();

        $localHostId = $this->localHostId();

        $this->addHostId('docker_volumes', $localHostId);
        $this->addHostId('backup_jobs', $localHostId);
        $this->addHostId('backup_runs', $localHostId);
        $this->addHostId('restore_runs', $localHostId);

        $this->replaceDockerVolumeNameUniqueIndex();
    }

    public function down(): void
    {
        if (Schema::hasTable('docker_volumes') && DB::table('docker_volumes')->select('name')->groupBy('name')->havingRaw('count(*) > 1')->exists()) {
            throw new RuntimeException('Cannot roll back host support while Docker volume names are duplicated across hosts.');
        }

        $this->dropScopedDockerVolumeNameUniqueIndex();

        $this->dropHostId('restore_runs');
        $this->dropHostId('backup_runs');
        $this->dropHostId('backup_jobs');
        $this->dropHostId('docker_volumes');
        $this->restoreDockerVolumeNameUniqueIndex();

        Schema::dropIfExists('hosts');
    }

    private function createHostsTable(): void
    {
        if (Schema::hasTable('hosts')) {
            return;
        }

        Schema::create('hosts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->index();
            $table->string('status')->default(self::LOCAL_HOST_STATUS)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->string('agent_version')->nullable();
            $table->string('docker_version')->nullable();
            $table->json('capabilities')->nullable();
            $table->json('metadata')->nullable();
            $table->string('enrollment_token_hash')->nullable()->index();
            $table->timestamp('enrollment_token_expires_at')->nullable()->index();
            $table->timestamp('enrolled_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    private function localHostId(): int
    {
        $localHostId = DB::table('hosts')
            ->where('type', self::LOCAL_HOST_TYPE)
            ->value('id');

        if ($localHostId !== null) {
            return (int) $localHostId;
        }

        $now = now();

        return (int) DB::table('hosts')->insertGetId([
            'name' => 'Local Docker Host',
            'type' => self::LOCAL_HOST_TYPE,
            'status' => self::LOCAL_HOST_STATUS,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function addHostId(string $tableName, int $localHostId): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        if (! Schema::hasColumn($tableName, 'host_id')) {
            Schema::table($tableName, function (Blueprint $table) use ($localHostId) {
                $table->foreignId('host_id')
                    ->default($localHostId)
                    ->after('id')
                    ->constrained('hosts')
                    ->restrictOnDelete();
            });
        }

        DB::table($tableName)
            ->whereNull('host_id')
            ->update(['host_id' => $localHostId]);
    }

    private function replaceDockerVolumeNameUniqueIndex(): void
    {
        if (! Schema::hasTable('docker_volumes')) {
            return;
        }

        if (Schema::hasIndex('docker_volumes', ['name'], 'unique')) {
            Schema::table('docker_volumes', function (Blueprint $table) {
                $table->dropUnique('docker_volumes_name_unique');
            });
        }

        if (! Schema::hasIndex('docker_volumes', ['host_id', 'name'], 'unique')) {
            Schema::table('docker_volumes', function (Blueprint $table) {
                $table->unique(['host_id', 'name']);
            });
        }
    }

    private function dropScopedDockerVolumeNameUniqueIndex(): void
    {
        if (! Schema::hasTable('docker_volumes')) {
            return;
        }

        if (Schema::hasIndex('docker_volumes', ['host_id', 'name'], 'unique')) {
            Schema::table('docker_volumes', function (Blueprint $table) {
                $table->dropUnique('docker_volumes_host_id_name_unique');
            });
        }
    }

    private function restoreDockerVolumeNameUniqueIndex(): void
    {
        if (Schema::hasTable('docker_volumes') && ! Schema::hasIndex('docker_volumes', ['name'], 'unique')) {
            Schema::table('docker_volumes', function (Blueprint $table) {
                $table->unique('name');
            });
        }
    }

    private function dropHostId(string $tableName): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'host_id')) {
            return;
        }

        $hasHostForeignKey = $this->hasForeignKey($tableName, 'host_id');

        Schema::table($tableName, function (Blueprint $table) use ($hasHostForeignKey) {
            if ($hasHostForeignKey) {
                $table->dropForeign(['host_id']);
            }

            $table->dropColumn('host_id');
        });
    }

    private function hasForeignKey(string $tableName, string $column): bool
    {
        foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
            if (in_array($column, $foreignKey['columns'], true)) {
                return true;
            }
        }

        return false;
    }
};
