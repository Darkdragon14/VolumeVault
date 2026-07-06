<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_runs', function (Blueprint $table) {
            // Progress marker the worker refreshes while no Docker backup container
            // exists yet: the (possibly long) sequential container-stop phase before
            // the backup container is created, and the post-success finalization
            // (archive-metadata listing + notifications) during which the job still
            // holds its overlap lock. Stale-run reconciliation uses it to tell a
            // healthy-but-slow run from a dead one, mirroring restore_runs.
            $table->timestamp('last_heartbeat_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('backup_runs', function (Blueprint $table) {
            $table->dropColumn('last_heartbeat_at');
        });
    }
};
