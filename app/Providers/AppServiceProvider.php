<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Anchor password reset links to APP_URL so a poisoned request host
        // (e.g. a spoofed X-Forwarded-Host) cannot redirect the reset token
        // to an attacker-controlled domain.
        ResetPassword::createUrlUsing(function (CanResetPassword $notifiable, string $token): string {
            return rtrim((string) config('app.url'), '/').route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], false);
        });
    }
}
