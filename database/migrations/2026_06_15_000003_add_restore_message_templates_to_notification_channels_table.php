<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_channels', function (Blueprint $table) {
            $table->string('restore_title_template')->nullable()->after('body_template');
            $table->text('restore_body_template')->nullable()->after('restore_title_template');
        });
    }

    public function down(): void
    {
        Schema::table('notification_channels', function (Blueprint $table) {
            $table->dropColumn(['restore_title_template', 'restore_body_template']);
        });
    }
};
