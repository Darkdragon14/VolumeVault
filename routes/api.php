<?php

use App\Http\Controllers\Api\V1\BackupJobController;
use App\Http\Controllers\Api\V1\BackupRunController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DestinationController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NotificationChannelController;
use App\Http\Controllers\Api\V1\OpenApiController;
use App\Http\Controllers\Api\V1\RestoreController;
use App\Http\Controllers\Api\V1\RestoreRunController;
use App\Http\Controllers\Api\V1\VolumeController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/openapi.json', OpenApiController::class);

    Route::middleware(['auth:sanctum', 'abilities:read'])->group(function () {
        Route::get('/me', MeController::class);
        Route::get('/dashboard', DashboardController::class);
        Route::get('/volumes', [VolumeController::class, 'index']);
        Route::get('/backup-jobs', [BackupJobController::class, 'index']);
        Route::get('/backup-jobs/{backupJob}', [BackupJobController::class, 'show']);
        Route::get('/backup-jobs/{backupJob}/backups', [BackupJobController::class, 'backups'])->middleware('admin');
        Route::get('/backup-runs', [BackupRunController::class, 'index']);
        Route::get('/backup-runs/{backupRun}', [BackupRunController::class, 'show']);
        Route::get('/restore-runs', [RestoreRunController::class, 'index']);
        Route::get('/restore-runs/{restoreRun}', [RestoreRunController::class, 'show']);
        Route::get('/destinations', [DestinationController::class, 'index'])->middleware('admin');
        Route::get('/destinations/{destination}', [DestinationController::class, 'show'])->middleware('admin');
        Route::get('/notifications', [NotificationChannelController::class, 'index'])->middleware('admin');
        Route::get('/notifications/{notification}', [NotificationChannelController::class, 'show'])->middleware('admin');
    });

    Route::middleware(['auth:sanctum', 'abilities:write', 'admin'])->group(function () {
        Route::post('/volumes/sync', [VolumeController::class, 'sync']);
        Route::post('/backup-jobs', [BackupJobController::class, 'store']);
        Route::put('/backup-jobs/{backupJob}', [BackupJobController::class, 'update']);
        Route::delete('/backup-jobs/{backupJob}', [BackupJobController::class, 'destroy']);
        Route::post('/backup-jobs/{backupJob}/run', [BackupJobController::class, 'runNow']);
        Route::post('/backup-jobs/{backupJob}/pause', [BackupJobController::class, 'pause']);
        Route::post('/backup-jobs/{backupJob}/resume', [BackupJobController::class, 'resume']);
        Route::post('/backup-jobs/{backupJob}/restore', [RestoreController::class, 'store']);
        Route::post('/destinations', [DestinationController::class, 'store']);
        Route::put('/destinations/{destination}', [DestinationController::class, 'update']);
        Route::delete('/destinations/{destination}', [DestinationController::class, 'destroy']);
        Route::post('/destinations/{destination}/test', [DestinationController::class, 'test']);
        Route::post('/notifications/{notification}/test', [NotificationChannelController::class, 'test']);
    });
});
