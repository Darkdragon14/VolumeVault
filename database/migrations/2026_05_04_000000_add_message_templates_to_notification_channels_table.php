<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_channels', function (Blueprint $table) {
            $table->string('title_template')->nullable()->after('scope');
            $table->text('body_template')->nullable()->after('title_template');
        });
    }

    public function down(): void
    {
        Schema::table('notification_channels', function (Blueprint $table) {
            $table->dropColumn(['title_template', 'body_template']);
        });
    }
};
