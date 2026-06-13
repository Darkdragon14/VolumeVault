<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_jobs', function (Blueprint $table) {
            $table->string('backup_filename_template')->nullable()->after('backup_exclude_regexp');
        });
    }

    public function down(): void
    {
        Schema::table('backup_jobs', function (Blueprint $table) {
            $table->dropColumn('backup_filename_template');
        });
    }
};
