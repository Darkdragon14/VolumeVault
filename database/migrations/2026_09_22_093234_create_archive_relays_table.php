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
        Schema::create('archive_relays', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('restore_run_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('backup_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_docker_host_id')->constrained('docker_hosts')->restrictOnDelete();
            $table->foreignId('target_docker_host_id')->constrained('docker_hosts')->restrictOnDelete();
            $table->foreignUuid('source_agent_operation_id')->nullable()->unique()->constrained('agent_operations')->restrictOnDelete();
            $table->text('destination_snapshot');
            $table->text('source_key');
            $table->string('status')->default('pending')->index();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->unsignedBigInteger('uploaded_bytes')->default(0);
            $table->unsignedBigInteger('downloaded_bytes')->default(0);
            $table->unsignedBigInteger('reserved_bytes');
            $table->text('error_message')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('cleaned_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('archive_relays');
    }
};
