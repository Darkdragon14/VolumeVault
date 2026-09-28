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
        Schema::table('backup_group_runs', function (Blueprint $table) {
            $table->json('member_run_ids')->nullable();
            $table->string('failure_policy_snapshot')->nullable();
            $table->unsignedBigInteger('current_member_run_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('backup_group_runs', function (Blueprint $table) {
            $table->dropColumn(['member_run_ids', 'failure_policy_snapshot', 'current_member_run_id']);
        });
    }
};
