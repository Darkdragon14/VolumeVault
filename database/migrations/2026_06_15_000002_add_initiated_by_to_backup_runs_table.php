<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_runs', function (Blueprint $table) {
            $table->foreignId('initiated_by_user_id')->nullable()->after('backup_job_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('backup_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('initiated_by_user_id');
        });
    }
};
