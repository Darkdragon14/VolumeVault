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
        Schema::table('docker_hosts', function (Blueprint $table) {
            Schema::table('docker_hosts', function (Blueprint $table): void {
                $table->string('docker_version', 100)->nullable();
                $table->unsignedInteger('docker_container_count')->nullable();
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('docker_hosts', function (Blueprint $table) {
            Schema::table('docker_hosts', function (Blueprint $table): void {
                $table->dropColumn(['docker_version', 'docker_container_count']);
            });
        });
    }
};
