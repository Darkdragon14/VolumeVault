<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_jobs', function (Blueprint $table): void {
            $table->string('configuration_source')->default('manual')->index();
            $table->string('configuration_key')->nullable()->unique();
            $table->json('label_origin')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('backup_jobs', function (Blueprint $table): void {
            $table->dropUnique(['configuration_key']);
            $table->dropIndex(['configuration_source']);
            $table->dropColumn(['configuration_source', 'configuration_key', 'label_origin']);
        });
    }
};
