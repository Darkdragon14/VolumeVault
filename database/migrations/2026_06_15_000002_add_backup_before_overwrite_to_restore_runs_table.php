<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restore_runs', function (Blueprint $table) {
            $table->boolean('backup_before_overwrite')->default(false)->after('mode');
            $table->foreignId('pre_restore_backup_run_id')->nullable()->after('backup_before_overwrite')->constrained('backup_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('restore_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pre_restore_backup_run_id');
            $table->dropColumn('backup_before_overwrite');
        });
    }
};
