<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A backup group owns the schedule + notifications + failure policy for a
        // set of member backup jobs. Members stay normal BackupJob rows (one source
        // each) so the existing backup/restore pipeline runs unchanged; the group
        // only orchestrates them and emits a single aggregated notification.
        Schema::create('backup_job_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('schedule_type');
            $table->json('schedule_config')->nullable();
            $table->string('cron_expression')->nullable();
            $table->string('timezone')->nullable();
            $table->string('status')->default('active')->index();
            $table->text('pause_reason')->nullable();
            $table->string('failure_policy')->default('continue');
            $table->boolean('notifications_enabled')->default(true)->index();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamp('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('backup_job_group_notification_channel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_job_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('notification_channel_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['backup_job_group_id', 'notification_channel_id'], 'backup_job_group_notification_unique');
        });

        Schema::create('backup_group_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_job_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('queued')->index();
            $table->string('trigger');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('total_members')->default(0);
            $table->unsignedInteger('succeeded_members')->default(0);
            $table->unsignedInteger('failed_members')->default(0);
            $table->text('error_message')->nullable();
            $table->longText('logs')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamps();
        });

        // Membership link. Null = standalone job (current behaviour, untouched).
        // Non-null = the job is scheduled and notified by its group, not itself.
        Schema::table('backup_jobs', function (Blueprint $table) {
            $table->foreignId('backup_job_group_id')->nullable()->after('name')->constrained()->nullOnDelete();
        });

        // Links a member run to the group run it belongs to. Null = ordinary run.
        // Used to suppress the member run's own notifications and to aggregate the
        // group run's outcome.
        Schema::table('backup_runs', function (Blueprint $table) {
            $table->foreignId('backup_group_run_id')->nullable()->after('backup_job_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('backup_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('backup_group_run_id');
        });

        Schema::table('backup_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('backup_job_group_id');
        });

        Schema::dropIfExists('backup_group_runs');
        Schema::dropIfExists('backup_job_group_notification_channel');
        Schema::dropIfExists('backup_job_groups');
    }
};
