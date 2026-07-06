<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'date_locale')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('date_locale', 12)->nullable()->after('locale');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'date_locale')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('date_locale');
            });
        }
    }
};
