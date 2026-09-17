<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_runs', function (Blueprint $table): void {
            $table->uuid('dispatch_token')->nullable()->after('status');
            $table->timestamp('dispatch_attempted_at')->nullable()->after('dispatch_token');
            $table->timestamp('dispatch_published_at')->nullable()->after('dispatch_attempted_at');
            $table->index(['status', 'dispatch_published_at', 'dispatch_attempted_at'], 'backup_runs_dispatch_lease_index');
        });

        Schema::table('restore_runs', function (Blueprint $table): void {
            $table->uuid('dispatch_token')->nullable()->after('status');
            $table->timestamp('dispatch_attempted_at')->nullable()->after('dispatch_token');
            $table->timestamp('dispatch_published_at')->nullable()->after('dispatch_attempted_at');
            $table->index(['status', 'dispatch_published_at', 'dispatch_attempted_at'], 'restore_runs_dispatch_lease_index');
        });

        Schema::table('backup_group_runs', function (Blueprint $table): void {
            $table->uuid('dispatch_token')->nullable()->after('status');
            $table->timestamp('dispatch_attempted_at')->nullable()->after('dispatch_token');
            $table->timestamp('dispatch_published_at')->nullable()->after('dispatch_attempted_at');
            $table->index(['status', 'dispatch_published_at', 'dispatch_attempted_at'], 'group_runs_dispatch_lease_index');
        });
    }

    public function down(): void
    {
        Schema::table('backup_runs', function (Blueprint $table): void {
            $table->dropIndex('backup_runs_dispatch_lease_index');
            $table->dropColumn(['dispatch_token', 'dispatch_attempted_at', 'dispatch_published_at']);
        });

        Schema::table('restore_runs', function (Blueprint $table): void {
            $table->dropIndex('restore_runs_dispatch_lease_index');
            $table->dropColumn(['dispatch_token', 'dispatch_attempted_at', 'dispatch_published_at']);
        });

        Schema::table('backup_group_runs', function (Blueprint $table): void {
            $table->dropIndex('group_runs_dispatch_lease_index');
            $table->dropColumn(['dispatch_token', 'dispatch_attempted_at', 'dispatch_published_at']);
        });
    }
};
