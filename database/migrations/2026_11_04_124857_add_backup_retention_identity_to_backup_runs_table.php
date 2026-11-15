<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('backup_runs', function (Blueprint $table) {
            $table->json('backup_retention_identity')->nullable();
            $table->index('backup_destination_locator_fingerprint', 'backup_runs_retention_destination_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('backup_runs', function (Blueprint $table) {
            $table->dropColumn('backup_retention_identity');
            $table->dropIndex('backup_runs_retention_destination_index');
        });
    }
};
