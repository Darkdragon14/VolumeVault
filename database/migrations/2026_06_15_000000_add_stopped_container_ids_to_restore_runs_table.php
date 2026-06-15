<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restore_runs', function (Blueprint $table) {
            $table->json('stopped_container_ids')->nullable()->after('affected_containers');
        });
    }

    public function down(): void
    {
        Schema::table('restore_runs', function (Blueprint $table) {
            $table->dropColumn('stopped_container_ids');
        });
    }
};
