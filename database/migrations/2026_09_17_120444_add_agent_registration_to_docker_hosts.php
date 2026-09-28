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
                $table->string('agent_enrollment_hash', 64)->nullable();
                $table->timestamp('agent_enrollment_expires_at')->nullable();
                $table->string('agent_token_hash', 64)->nullable();
                $table->uuid('agent_instance_id')->nullable();
                $table->timestamp('agent_registered_at')->nullable();
                $table->timestamp('agent_revoked_at')->nullable();
                $table->timestamp('last_seen_at')->nullable()->index();
                $table->timestamp('last_inventory_at')->nullable();
                $table->string('agent_version', 100)->nullable();
                $table->string('docker_status', 20)->nullable();
                $table->unsignedBigInteger('agent_inventory_sequence')->default(0);
                $table->json('agent_containers')->nullable();
                $table->json('agent_host_path_allowlist')->nullable();
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
                $table->dropIndex(['last_seen_at']);
                $table->dropColumn([
                    'agent_enrollment_hash', 'agent_enrollment_expires_at', 'agent_token_hash',
                    'agent_instance_id', 'agent_registered_at', 'agent_revoked_at', 'last_seen_at',
                    'last_inventory_at', 'agent_version', 'docker_status', 'agent_inventory_sequence',
                    'agent_containers', 'agent_host_path_allowlist',
                ]);
            });
        });
    }
};
