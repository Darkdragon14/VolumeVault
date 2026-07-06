<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\AlertRuleController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AvailableUpdateController;
use App\Http\Controllers\BackupGroupRunController;
use App\Http\Controllers\BackupJobController;
use App\Http\Controllers\BackupJobGroupController;
use App\Http\Controllers\BackupRunController;
use App\Http\Controllers\ChangelogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardPreferenceController;
use App\Http\Controllers\DestinationController;
use App\Http\Controllers\InstallationSaveController;
use App\Http\Controllers\NotificationChannelController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RestoreController;
use App\Http\Controllers\RestoreRunController;
use App\Http\Controllers\StackController;
use App\Http\Controllers\TwoFactorAuthController;
use App\Http\Controllers\TwoFactorChallengeController;
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
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:5,1')->name('login.store');
    Route::get('/forgot-password', [PasswordResetController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'store'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.update');
    Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.challenge');
    Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->middleware('throttle:5,1')->name('two-factor.challenge.store');
});

Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::patch('/user/locale', [UserLocaleController::class, 'update'])->name('user.locale.update');
    Route::patch('/user/theme', [UserThemeController::class, 'update'])->name('user.theme.update');
    Route::patch('/user/dashboard-preferences', [DashboardPreferenceController::class, 'update'])->name('user.dashboard-preferences.update');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/two-factor', [TwoFactorAuthController::class, 'store'])->name('two-factor.enable');
    Route::post('/profile/two-factor/confirm', [TwoFactorAuthController::class, 'confirm'])->name('two-factor.confirm');
    Route::delete('/profile/two-factor', [TwoFactorAuthController::class, 'destroy'])->name('two-factor.disable');
    Route::post('/profile/two-factor/recovery-codes', [TwoFactorAuthController::class, 'recoveryCodes'])->name('two-factor.recovery-codes');
    Route::delete('/profile/two-factor/devices', [TwoFactorAuthController::class, 'destroyDevices'])->name('two-factor.devices.clear');
    Route::delete('/profile/two-factor/devices/{device}', [TwoFactorAuthController::class, 'destroyDevice'])->name('two-factor.devices.destroy');
    Route::get('/changelog', [ChangelogController::class, 'index'])->name('changelog.index');
    Route::patch('/changelog/seen', [ChangelogController::class, 'seen'])->name('changelog.seen');
    Route::patch('/updates/available/dismiss', [AvailableUpdateController::class, 'dismiss'])->name('updates.available.dismiss');

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/volumes', [VolumeController::class, 'index'])->name('volumes.index');
    Route::post('/volumes/sync', [VolumeController::class, 'sync'])->middleware('admin')->name('volumes.sync');
    Route::get('/stacks', [StackController::class, 'index'])->name('stacks.index');
    Route::get('/alerts', [AlertController::class, 'index'])->name('alerts.index');

    Route::resource('backup-jobs', BackupJobController::class)->only(['index']);
    Route::resource('backup-groups', BackupJobGroupController::class)->only(['index']);
    Route::middleware('admin')->group(function () {
        Route::get('/alerts/settings', [AlertRuleController::class, 'edit'])->name('alerts.settings.edit');
        Route::put('/alerts/settings', [AlertRuleController::class, 'update'])->name('alerts.settings.update');

        Route::post('/stacks/backup', [StackController::class, 'backup'])->name('stacks.backup');

        Route::resource('backup-jobs', BackupJobController::class)->except(['index', 'show']);
        Route::post('/backup-jobs/{backupJob}/run', [BackupJobController::class, 'runNow'])->name('backup-jobs.run');
        Route::post('/backup-jobs/{backupJob}/pause', [BackupJobController::class, 'pause'])->name('backup-jobs.pause');
        Route::post('/backup-jobs/{backupJob}/resume', [BackupJobController::class, 'resume'])->name('backup-jobs.resume');

        Route::resource('backup-groups', BackupJobGroupController::class)->except(['index', 'show']);
        Route::post('/backup-groups/{backupGroup}/run', [BackupJobGroupController::class, 'runNow'])->name('backup-groups.run');
        Route::post('/backup-groups/{backupGroup}/pause', [BackupJobGroupController::class, 'pause'])->name('backup-groups.pause');
        Route::post('/backup-groups/{backupGroup}/resume', [BackupJobGroupController::class, 'resume'])->name('backup-groups.resume');
        Route::patch('/backup-groups/{backupGroup}/notifications', [BackupJobGroupController::class, 'toggleNotifications'])->name('backup-groups.notifications');

        Route::resource('destinations', DestinationController::class)->except(['show']);
        Route::post('/destinations/host-key', [DestinationController::class, 'hostKey'])->name('destinations.host-key');
        Route::patch('/destinations/{destination}/active', [DestinationController::class, 'updateActive'])->name('destinations.active');
        Route::post('/destinations/{destination}/test', [DestinationController::class, 'test'])->name('destinations.test');

        Route::get('/installation-save', [InstallationSaveController::class, 'index'])->name('installation-save.index');
        Route::get('/installation-save/download', [InstallationSaveController::class, 'download'])->name('installation-save.download');
        Route::post('/installation-save/upload', [InstallationSaveController::class, 'upload'])->name('installation-save.upload');

        Route::resource('notifications', NotificationChannelController::class)->parameters(['notifications' => 'notification'])->except(['show']);
        Route::patch('/notifications/{notification}/active', [NotificationChannelController::class, 'updateActive'])->name('notifications.active');
        Route::post('/notifications/{notification}/test', [NotificationChannelController::class, 'test'])->name('notifications.test');

        Route::resource('users', UserController::class)->except(['show']);
        Route::delete('/users/{user}/two-factor', [UserController::class, 'resetTwoFactor'])->name('users.two-factor.reset');
        Route::resource('api-tokens', ApiTokenController::class)->only(['index', 'store', 'destroy']);

        Route::get('/backup-jobs/{backupJob}/restore', [RestoreController::class, 'create'])->name('backup-jobs.restore');
        Route::get('/backup-jobs/{backupJob}/backups', [RestoreController::class, 'listBackups'])->name('backup-jobs.backups');
        Route::post('/backup-jobs/{backupJob}/restore', [RestoreController::class, 'store'])->name('backup-jobs.restore.store');
    });

    Route::get('/alerts/{alert}', [AlertController::class, 'show'])->name('alerts.show');

    Route::resource('backup-jobs', BackupJobController::class)->only(['show']);

    Route::get('/backup-runs/{backupRun}', [BackupRunController::class, 'show'])->name('backup-runs.show');

    Route::get('/backup-group-runs/{backupGroupRun}', [BackupGroupRunController::class, 'show'])->name('backup-group-runs.show');

    Route::get('/restore-runs/{restoreRun}', [RestoreRunController::class, 'show'])->name('restore-runs.show');
});
