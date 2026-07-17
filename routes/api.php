<?php

use App\Http\Controllers\Api\V1\Agent\CommandCompletionController;
use App\Http\Controllers\Api\V1\Agent\CommandLeaseController;
use App\Http\Controllers\Api\V1\Agent\CommandLogController;
use App\Http\Controllers\Api\V1\Agent\EnrollmentController;
use App\Http\Controllers\Api\V1\Agent\HeartbeatController;
use App\Http\Controllers\Api\V1\BackupGroupRunController;
use App\Http\Controllers\Api\V1\BackupJobController;
use App\Http\Controllers\Api\V1\BackupJobGroupController;
use App\Http\Controllers\Api\V1\BackupRunController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DestinationController;
use App\Http\Controllers\Api\V1\HostController;
use App\Http\Controllers\Api\V1\HostPathAllowlistController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NotificationChannelController;
use App\Http\Controllers\Api\V1\OpenApiController;
use App\Http\Controllers\Api\V1\RestoreController;
use App\Http\Controllers\Api\V1\RestoreRunController;
use App\Http\Controllers\Api\V1\StackController;
use App\Http\Controllers\Api\V1\VolumeController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/openapi.json', OpenApiController::class);

    Route::prefix('agent')->group(function () {
        Route::post('/enroll', EnrollmentController::class)->middleware('throttle:agent-enrollment');
        Route::middleware('throttle:agent')->group(function () {
            Route::post('/heartbeat', HeartbeatController::class);
            Route::post('/commands/lease', CommandLeaseController::class);
            Route::post('/commands/{agentCommand}/logs', CommandLogController::class);
            Route::post('/commands/{agentCommand}/complete', CommandCompletionController::class);
        });
    });

    Route::middleware(['auth:sanctum', 'abilities:read'])->group(function () {
        Route::get('/me', MeController::class);
        Route::get('/hosts', [HostController::class, 'index']);
        Route::get('/hosts/{host}', [HostController::class, 'show']);
        Route::get('/dashboard', DashboardController::class);
        Route::get('/volumes', [VolumeController::class, 'index']);
        Route::get('/backup-jobs', [BackupJobController::class, 'index']);
        Route::get('/backup-jobs/{backupJob}', [BackupJobController::class, 'show']);
        Route::get('/backup-jobs/{backupJob}/backups', [BackupJobController::class, 'backups'])->middleware('admin');
        Route::get('/backup-groups', [BackupJobGroupController::class, 'index']);
        Route::get('/backup-groups/{backupGroup}', [BackupJobGroupController::class, 'show']);
        Route::get('/backup-group-runs', [BackupGroupRunController::class, 'index']);
        Route::get('/backup-group-runs/{backupGroupRun}', [BackupGroupRunController::class, 'show']);
        Route::get('/backup-runs', [BackupRunController::class, 'index']);
        Route::get('/backup-runs/{backupRun}', [BackupRunController::class, 'show']);
        Route::get('/restore-runs', [RestoreRunController::class, 'index']);
        Route::get('/restore-runs/{restoreRun}', [RestoreRunController::class, 'show']);
        Route::get('/host-path-allowlist', HostPathAllowlistController::class)->middleware('admin');
        Route::get('/destinations', [DestinationController::class, 'index'])->middleware('admin');
        Route::get('/destinations/{destination}', [DestinationController::class, 'show'])->middleware('admin');
        Route::get('/notifications', [NotificationChannelController::class, 'index'])->middleware('admin');
        Route::get('/notifications/{notification}', [NotificationChannelController::class, 'show'])->middleware('admin');
    });

    Route::middleware(['auth:sanctum', 'abilities:write', 'admin'])->group(function () {
        Route::post('/volumes/sync', [VolumeController::class, 'sync']);
        Route::post('/hosts', [HostController::class, 'store']);
        Route::put('/hosts/{host}', [HostController::class, 'update']);
        Route::post('/hosts/{host}/activate', [HostController::class, 'activate']);
        Route::post('/hosts/{host}/deactivate', [HostController::class, 'deactivate']);
        Route::post('/hosts/{host}/enrollment-token', [HostController::class, 'enrollmentToken']);
        Route::post('/stacks/backup', [StackController::class, 'backup']);
        Route::post('/backup-jobs', [BackupJobController::class, 'store']);
        Route::put('/backup-jobs/{backupJob}', [BackupJobController::class, 'update']);
        Route::delete('/backup-jobs/{backupJob}', [BackupJobController::class, 'destroy']);
        Route::post('/backup-jobs/{backupJob}/run', [BackupJobController::class, 'runNow']);
        Route::post('/backup-jobs/{backupJob}/pause', [BackupJobController::class, 'pause']);
        Route::post('/backup-jobs/{backupJob}/resume', [BackupJobController::class, 'resume']);
        Route::post('/backup-jobs/{backupJob}/restore', [RestoreController::class, 'store']);
        Route::post('/backup-groups', [BackupJobGroupController::class, 'store']);
        Route::put('/backup-groups/{backupGroup}', [BackupJobGroupController::class, 'update']);
        Route::delete('/backup-groups/{backupGroup}', [BackupJobGroupController::class, 'destroy']);
        Route::post('/backup-groups/{backupGroup}/run', [BackupJobGroupController::class, 'runNow']);
        Route::post('/backup-groups/{backupGroup}/pause', [BackupJobGroupController::class, 'pause']);
        Route::post('/backup-groups/{backupGroup}/resume', [BackupJobGroupController::class, 'resume']);
        Route::patch('/backup-groups/{backupGroup}/notifications', [BackupJobGroupController::class, 'toggleNotifications']);
        Route::post('/destinations', [DestinationController::class, 'store']);
        Route::post('/destinations/host-key', [DestinationController::class, 'hostKey']);
        Route::put('/destinations/{destination}', [DestinationController::class, 'update']);
        Route::delete('/destinations/{destination}', [DestinationController::class, 'destroy']);
        Route::post('/destinations/{destination}/test', [DestinationController::class, 'test']);
        Route::put('/notifications/{notification}', [NotificationChannelController::class, 'update']);
        Route::post('/notifications/{notification}/test', [NotificationChannelController::class, 'test']);
    });
});
