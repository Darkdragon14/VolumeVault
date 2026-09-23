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
        Schema::table('backup_destinations', function (Blueprint $table) {
            $table->foreignId('storage_measurement_host_id')->nullable()->constrained('docker_hosts')->restrictOnDelete();
            $table->uuid('storage_measurement_revision')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('backup_destinations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('storage_measurement_host_id');
            $table->dropColumn('storage_measurement_revision');
        });
    }
};
