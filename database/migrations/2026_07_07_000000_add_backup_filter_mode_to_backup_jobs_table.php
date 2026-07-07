<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_jobs', function (Blueprint $table) {
            // "exclude" keeps the historical behaviour for existing jobs; "include"
            // only keeps the comma-separated paths listed in backup_include_paths.
            $table->string('backup_filter_mode')->default('exclude')->after('backup_exclude_regexp');
            $table->text('backup_include_paths')->nullable()->after('backup_filter_mode');
        });
    }

    public function down(): void
    {
        Schema::table('backup_jobs', function (Blueprint $table) {
            $table->dropColumn(['backup_filter_mode', 'backup_include_paths']);
        });
    }
};
