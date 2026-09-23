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
        Schema::table('restore_runs', function (Blueprint $table) {
            $table->boolean('docker_container_cleanup_pending')->default(false)->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restore_runs', function (Blueprint $table) {
            $table->dropColumn('docker_container_cleanup_pending');
        });
    }
};
