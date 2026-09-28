<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('agent-enrollment', function (Request $request): Limit {
            return Limit::perMinute(10)->by('agent-enrollment:'.$request->ip());
        });

        RateLimiter::for('agent-traffic', function (Request $request): Limit {
            return Limit::perMinute(180)->by('agent-traffic:'.$request->attributes->get('docker_host')->uuid);
        });

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
