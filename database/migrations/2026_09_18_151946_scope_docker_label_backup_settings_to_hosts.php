<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('docker_label_backup_settings', function (Blueprint $table): void {
            $table->foreignId('docker_host_id')->default(1)->unique()->constrained()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('docker_label_backup_settings')->where('docker_host_id', '!=', 1)->exists()) {
            throw new RuntimeException('Remove remote Docker label settings before rolling back host scoping.');
        }

        Schema::table('docker_label_backup_settings', function (Blueprint $table): void {
            $table->dropUnique(['docker_host_id']);
            $table->dropConstrainedForeignId('docker_host_id');
        });
    }
};
