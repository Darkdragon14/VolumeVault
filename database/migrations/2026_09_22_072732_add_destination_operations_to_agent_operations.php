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
        Schema::table('agent_operations', function (Blueprint $table): void {
            $table->foreignId('backup_destination_id')->nullable()->constrained()->nullOnDelete();
            $table->string('destination_action')->nullable();
            $table->string('locator_fingerprint', 64)->nullable();
            $table->text('result')->nullable();
            $table->index(['backup_destination_id', 'destination_action', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_operations', function (Blueprint $table): void {
            $table->dropIndex(['backup_destination_id', 'destination_action', 'status']);
            $table->dropConstrainedForeignId('backup_destination_id');
            $table->dropColumn(['destination_action', 'locator_fingerprint', 'result']);
        });
    }
};
