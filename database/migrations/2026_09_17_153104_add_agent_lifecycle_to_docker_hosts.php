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
                $table->timestamp('maintenance_requested_at')->nullable();
                $table->uuid('maintenance_token')->nullable();
                $table->uuid('agent_maintenance_token')->nullable();
                $table->unsignedInteger('agent_protocol_version')->nullable();
                $table->json('agent_capabilities')->nullable();
                $table->unsignedInteger('agent_active_operations')->nullable();
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
                $table->dropColumn([
                    'maintenance_requested_at', 'maintenance_token', 'agent_maintenance_token',
                    'agent_protocol_version', 'agent_capabilities', 'agent_active_operations',
                ]);
            });
        });
    }
};
