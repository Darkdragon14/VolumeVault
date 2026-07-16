<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hosts') && ! Schema::hasColumn('hosts', 'agent_token_hash')) {
            Schema::table('hosts', function (Blueprint $table) {
                $table->string('agent_token_hash', 64)->nullable()->index()->after('enrollment_token_expires_at');
            });
        }

        if (Schema::hasTable('agent_commands') && ! Schema::hasColumn('agent_commands', 'lease_token_hash')) {
            Schema::table('agent_commands', function (Blueprint $table) {
                $table->string('lease_token_hash', 64)->nullable()->after('lease_until');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('agent_commands') && Schema::hasColumn('agent_commands', 'lease_token_hash')) {
            Schema::table('agent_commands', function (Blueprint $table) {
                $table->dropColumn('lease_token_hash');
            });
        }

        if (Schema::hasTable('hosts') && Schema::hasColumn('hosts', 'agent_token_hash')) {
            if (Schema::hasIndex('hosts', ['agent_token_hash'])) {
                Schema::table('hosts', function (Blueprint $table) {
                    $table->dropIndex(['agent_token_hash']);
                });
            }

            Schema::table('hosts', function (Blueprint $table) {
                $table->dropColumn('agent_token_hash');
            });
        }
    }
};
