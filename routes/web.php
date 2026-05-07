<?php

use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BackupJobController;
use App\Http\Controllers\BackupRunController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DestinationController;
use App\Http\Controllers\InstallationSaveController;
use App\Http\Controllers\NotificationChannelController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RestoreController;
use App\Http\Controllers\RestoreRunController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserLocaleController;
use App\Http\Controllers\UserThemeController;
use App\Http\Controllers\VolumeController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(User::exists() ? 'dashboard' : 'onboarding.create'));

Route::middleware('guest')->group(function () {
    Route::get('/onboarding', [OnboardingController::class, 'create'])->name('onboarding.create');
    Route::post('/onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');
    Route::post('/onboarding/import', [OnboardingController::class, 'import'])->name('onboarding.import');
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
    Route::get('/forgot-password', [PasswordResetController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'store'])->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.update');
});

Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::patch('/user/locale', [UserLocaleController::class, 'update'])->name('user.locale.update');
    Route::patch('/user/theme', [UserThemeController::class, 'update'])->name('user.theme.update');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/volumes', [VolumeController::class, 'index'])->name('volumes.index');
    Route::post('/volumes/sync', [VolumeController::class, 'sync'])->middleware('admin')->name('volumes.sync');

    Route::resource('backup-jobs', BackupJobController::class)->only(['index']);
    Route::middleware('admin')->group(function () {
        Route::resource('backup-jobs', BackupJobController::class)->except(['index', 'show']);
        Route::post('/backup-jobs/{backupJob}/run', [BackupJobController::class, 'runNow'])->name('backup-jobs.run');
        Route::post('/backup-jobs/{backupJob}/pause', [BackupJobController::class, 'pause'])->name('backup-jobs.pause');
        Route::post('/backup-jobs/{backupJob}/resume', [BackupJobController::class, 'resume'])->name('backup-jobs.resume');

        Route::resource('destinations', DestinationController::class)->except(['show']);
        Route::post('/destinations/{destination}/test', [DestinationController::class, 'test'])->name('destinations.test');

        Route::get('/installation-save', [InstallationSaveController::class, 'index'])->name('installation-save.index');
        Route::get('/installation-save/download', [InstallationSaveController::class, 'download'])->name('installation-save.download');
        Route::post('/installation-save/upload', [InstallationSaveController::class, 'upload'])->name('installation-save.upload');

        Route::resource('notifications', NotificationChannelController::class)->parameters(['notifications' => 'notification'])->except(['show']);
        Route::post('/notifications/{notification}/test', [NotificationChannelController::class, 'test'])->name('notifications.test');

        Route::resource('users', UserController::class)->except(['show']);
        Route::resource('api-tokens', ApiTokenController::class)->only(['index', 'store', 'destroy']);

        Route::get('/backup-jobs/{backupJob}/restore', [RestoreController::class, 'create'])->name('backup-jobs.restore');
        Route::get('/backup-jobs/{backupJob}/backups', [RestoreController::class, 'listBackups'])->name('backup-jobs.backups');
        Route::post('/backup-jobs/{backupJob}/restore', [RestoreController::class, 'store'])->name('backup-jobs.restore.store');
    });

    Route::resource('backup-jobs', BackupJobController::class)->only(['show']);

    Route::get('/backup-runs/{backupRun}', [BackupRunController::class, 'show'])->name('backup-runs.show');

    Route::get('/restore-runs/{restoreRun}', [RestoreRunController::class, 'show'])->name('restore-runs.show');
});
