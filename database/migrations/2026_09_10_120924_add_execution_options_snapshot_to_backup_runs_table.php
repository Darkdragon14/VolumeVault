<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_runs', function (Blueprint $table): void {
            $table->json('execution_options_snapshot')->nullable()->after('backup_filename');
        });
    }

    public function down(): void
    {
        Schema::table('backup_runs', function (Blueprint $table): void {
            $table->dropColumn('execution_options_snapshot');
        });
    }
};
