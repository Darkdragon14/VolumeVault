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
        Schema::table('hosts', function (Blueprint $table) {
            $table->foreignId('active_agent_command_id')->nullable()->after('agent_token_hash')->index();
            $table->uuid('enrollment_request_id')->nullable()->after('enrollment_token_expires_at');
            $table->timestamp('enrollment_token_consumed_at')->nullable()->after('enrollment_request_id');
        });

        Schema::table('agent_commands', function (Blueprint $table) {
            $table->uuid('lease_request_id')->nullable()->unique()->after('lease_token_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_commands', function (Blueprint $table) {
            $table->dropUnique(['lease_request_id']);
            $table->dropColumn('lease_request_id');
        });

        Schema::table('hosts', function (Blueprint $table) {
            $table->dropIndex(['active_agent_command_id']);
            $table->dropColumn([
                'active_agent_command_id',
                'enrollment_request_id',
                'enrollment_token_consumed_at',
            ]);
        });
    }
};
