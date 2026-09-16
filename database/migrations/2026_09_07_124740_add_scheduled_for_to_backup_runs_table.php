<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_runs', function (Blueprint $table): void {
            $table->timestamp('scheduled_for')->nullable()->after('trigger');
        });

        Schema::table('backup_group_runs', function (Blueprint $table): void {
            $table->timestamp('scheduled_for')->nullable()->after('trigger');
        });
    }

    public function down(): void
    {
        Schema::table('backup_runs', function (Blueprint $table): void {
            $table->dropColumn('scheduled_for');
        });

        Schema::table('backup_group_runs', function (Blueprint $table): void {
            $table->dropColumn('scheduled_for');
        });
    }
};
