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
        Schema::table('run_finalizations', function (Blueprint $table) {
            $table->text('remote_metadata_payload')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('run_finalizations', function (Blueprint $table) {
            $table->dropColumn('remote_metadata_payload');
        });
    }
};
