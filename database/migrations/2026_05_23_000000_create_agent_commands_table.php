<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_id')->constrained('hosts')->cascadeOnDelete();
            $table->string('type')->index();
            $table->string('status')->default('pending')->index();
            $table->json('payload')->nullable();
            $table->text('secret_payload')->nullable();
            $table->foreignId('backup_run_id')->nullable()->constrained('backup_runs')->nullOnDelete();
            $table->foreignId('restore_run_id')->nullable()->constrained('restore_runs')->nullOnDelete();
            $table->timestamp('lease_until')->nullable()->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['host_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_commands');
    }
};
