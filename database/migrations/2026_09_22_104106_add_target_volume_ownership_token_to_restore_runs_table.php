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
        Schema::table('restore_runs', function (Blueprint $table) {
            $table->string('target_volume_ownership_token', 64)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restore_runs', function (Blueprint $table) {
            $table->dropColumn('target_volume_ownership_token');
        });
    }
};
