<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_runs', function (Blueprint $table): void {
            $table->string('source_type_snapshot')->nullable()->after('trigger');
            $table->string('source_volume_name')->nullable()->index()->after('source_type_snapshot');
            $table->text('source_host_path')->nullable()->after('source_volume_name');
            $table->unsignedBigInteger('backup_destination_id_snapshot')->nullable()->index()->after('source_host_path');
            $table->string('backup_destination_name')->nullable()->after('backup_destination_id_snapshot');
            $table->string('backup_destination_provider')->nullable()->after('backup_destination_name');
            $table->string('backup_destination_locator_fingerprint', 64)->nullable()->after('backup_destination_provider');
            $table->string('backup_filename')->nullable()->after('backup_destination_locator_fingerprint');
            $table->boolean('archive_metadata_pending')->default(false)->index()->after('backup_size_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('backup_runs', function (Blueprint $table): void {
            $table->dropIndex(['source_volume_name']);
            $table->dropIndex(['backup_destination_id_snapshot']);
            $table->dropIndex(['archive_metadata_pending']);
        });

        Schema::table('backup_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'source_type_snapshot',
                'source_volume_name',
                'source_host_path',
                'backup_destination_id_snapshot',
                'backup_destination_name',
                'backup_destination_provider',
                'backup_destination_locator_fingerprint',
                'backup_filename',
                'archive_metadata_pending',
            ]);
        });
    }
};
