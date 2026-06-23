<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restore_runs', function (Blueprint $table) {
            // Progress marker the worker refreshes at each phase boundary (safety
            // backup, download, extraction). Stale-run reconciliation uses it to
            // tell a healthy-but-slow restore from a dead one while no Docker
            // container exists yet (the long download/safety-backup window).
            $table->timestamp('last_heartbeat_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('restore_runs', function (Blueprint $table) {
            $table->dropColumn('last_heartbeat_at');
        });
    }
};
