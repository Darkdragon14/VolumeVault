<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('backup_runs')
            ->where('archive_metadata_pending', true)
            ->orderBy('id')
            ->chunkById(100, function ($runs): void {
                $now = now();
                $rows = [];

                foreach ($runs as $run) {
                    $rows[] = [
                        'backup_run_id' => $run->id,
                        'restore_run_id' => null,
                        'backup_group_run_id' => null,
                        'notification_channel_id' => null,
                        'type' => 'archive_metadata',
                        'deduplication_key' => "backup-run:{$run->id}:archive-metadata",
                        'status' => 'pending',
                        'attempts' => 0,
                        'available_at' => $now,
                        'context' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('run_finalizations')->insertOrIgnore($rows);
            });
    }

    public function down(): void {}
};
