<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_jobs', function (Blueprint $table): void {
            $table->json('pending_label_reconciliation')->nullable();
            $table->text('label_reconciliation_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('backup_jobs', function (Blueprint $table): void {
            $table->dropColumn(['pending_label_reconciliation', 'label_reconciliation_error']);
        });
    }
};
