<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_jobs', function (Blueprint $table) {
            $table->text('backup_exclude_regexp')->nullable()->after('retention_count');
        });
    }

    public function down(): void
    {
        Schema::table('backup_jobs', function (Blueprint $table) {
            $table->dropColumn('backup_exclude_regexp');
        });
    }
};
