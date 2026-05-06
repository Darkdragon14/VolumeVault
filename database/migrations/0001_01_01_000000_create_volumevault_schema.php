<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'users',
            'password_reset_tokens',
            'sessions',
            'cache',
            'cache_locks',
            'jobs',
            'job_batches',
            'failed_jobs',
            'docker_volumes',
            'backup_destinations',
            'backup_jobs',
            'backup_runs',
            'restore_runs',
            'activity_logs',
            'notification_channels',
            'backup_job_notification_channel',
            'personal_access_tokens',
        ];

        $existingTables = array_filter($tables, fn (string $table): bool => Schema::hasTable($table));

        if (count($existingTables) === count($tables)) {
            return;
        }

        if ($existingTables !== []) {
            throw new RuntimeException('The database contains a partial VolumeVault schema. Restore a complete backup or reset the volume before running migrations.');
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('role')->default('user')->index();
            $table->string('locale', 5)->default('en');
            $table->string('theme', 10)->default('dark');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        Schema::create('docker_volumes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('driver')->nullable();
            $table->text('mountpoint')->nullable();
            $table->json('labels')->nullable();
            $table->json('options')->nullable();
            $table->boolean('exists')->default(true)->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('backup_destinations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('provider')->index();
            $table->text('endpoint')->nullable();
            $table->string('region')->nullable();
            $table->string('bucket');
            $table->string('path_prefix')->nullable();
            $table->text('access_key_id');
            $table->text('secret_access_key');
            $table->boolean('use_path_style_endpoint')->default(false);
            $table->json('settings')->nullable();
            $table->text('secrets')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status')->nullable();
            $table->text('last_test_error')->nullable();
            $table->timestamps();
        });

        Schema::create('backup_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('volume_name')->index();
            $table->foreignId('backup_destination_id')->constrained()->cascadeOnDelete();
            $table->string('schedule_type');
            $table->json('schedule_config')->nullable();
            $table->string('cron_expression')->nullable();
            $table->string('status')->default('active')->index();
            $table->text('pause_reason')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamp('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('retention_days')->nullable();
            $table->unsignedInteger('retention_count')->nullable();
            $table->boolean('stop_containers_before_backup')->default(false);
            $table->timestamps();
        });

        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_job_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('queued')->index();
            $table->string('trigger');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->longText('logs')->nullable();
            $table->text('error_message')->nullable();
            $table->string('docker_container_id')->nullable();
            $table->timestamps();
        });

        Schema::create('restore_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('backup_destination_id')->nullable()->constrained()->nullOnDelete();
            $table->string('selected_backup_key');
            $table->string('source_volume_name');
            $table->string('target_volume_name');
            $table->string('mode')->default('new_volume');
            $table->string('status')->default('queued')->index();
            $table->json('affected_containers')->nullable();
            $table->string('confirmation_text')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->longText('logs')->nullable();
            $table->text('error_message')->nullable();
            $table->string('docker_container_id')->nullable();
            $table->timestamps();
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event_type')->index();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('service')->index();
            $table->text('url');
            $table->string('notification_level')->default('error')->index();
            $table->string('scope')->default('all')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status')->nullable();
            $table->text('last_test_error')->nullable();
            $table->timestamps();
        });

        Schema::create('backup_job_notification_channel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('notification_channel_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['backup_job_id', 'notification_channel_id'], 'backup_job_notification_unique');
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('backup_job_notification_channel');
        Schema::dropIfExists('notification_channels');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('restore_runs');
        Schema::dropIfExists('backup_runs');
        Schema::dropIfExists('backup_jobs');
        Schema::dropIfExists('backup_destinations');
        Schema::dropIfExists('docker_volumes');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
