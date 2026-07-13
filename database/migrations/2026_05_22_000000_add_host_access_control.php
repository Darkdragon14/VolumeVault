<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'host_access_mode')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('host_access_mode')->default('all')->after('role')->index();
            });

            DB::table('users')
                ->whereNull('host_access_mode')
                ->update(['host_access_mode' => 'all']);
        }

        if (! Schema::hasTable('host_user')) {
            Schema::create('host_user', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('host_id')->constrained('hosts')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['user_id', 'host_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('host_user');

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'host_access_mode')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('host_access_mode');
            });
        }
    }
};
