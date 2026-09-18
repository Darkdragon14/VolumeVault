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
        Schema::create('agent_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('docker_host_id')->constrained()->restrictOnDelete();
            $table->foreignId('backup_run_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('restore_run_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('kind');
            $table->string('status')->default('pending');
            $table->text('payload')->nullable();
            $table->text('context')->nullable();
            $table->text('delivery_token')->nullable();
            $table->uuid('owner_instance_id')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('last_progress_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['docker_host_id', 'status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_operations');
    }
};
