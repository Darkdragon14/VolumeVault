<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_runs', function (Blueprint $table) {
            $table->boolean('docker_container_cleanup_pending')->default(false)->after('docker_container_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('backup_runs', function (Blueprint $table) {
            $table->dropIndex(['docker_container_cleanup_pending']);
            $table->dropColumn('docker_container_cleanup_pending');
        });
    }
};
