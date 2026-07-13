<?php

namespace App\Http\Middleware;

use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\User;
use App\Services\Changelog\AvailableUpdateChecker;
use App\Services\Changelog\Changelog;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        if ($request->user()) {
            app()->setLocale($request->user()->locale ?: User::DEFAULT_LOCALE);
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => fn () => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'role' => $request->user()->role,
                    'locale' => $request->user()->locale,
                    'date_locale' => $request->user()->date_locale,
                    'theme' => $request->user()->theme ?: User::DEFAULT_THEME,
                    'default_per_page' => $request->user()->default_per_page ?? 10,
                    'is_admin' => $request->user()->isAdmin(),
                ] : null,
            ],
            'can' => [
                'manageSensitiveData' => fn () => (bool) $request->user()?->isAdmin(),
                'runDockerActions' => fn () => (bool) $request->user()?->isAdmin(),
                'manageUsers' => fn () => (bool) $request->user()?->isAdmin(),
            ],
            'app' => [
                'timezone' => config('app.timezone'),
                'version' => config('app.version'),
                'locale' => $request->user()?->locale ?? User::DEFAULT_LOCALE,
                'locales' => User::SUPPORTED_LOCALES,
                'dateLocale' => $request->user()?->date_locale,
                'theme' => $request->user()?->theme ?? User::DEFAULT_THEME,
                'themes' => User::SUPPORTED_THEMES,
            ],
            'updateSummary' => fn () => $request->user()
                ? app(Changelog::class)->unreadForUser($request->user())
                : null,
            'availableUpdate' => fn () => $request->user()
                ? app(AvailableUpdateChecker::class)->forUser($request->user())
                : null,
            'activeAlertCount' => fn () => $request->user()
                ? Alert::where('status', AlertStatus::Active->value)->count()
                : 0,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'api_token' => fn () => $request->session()->get('api_token'),
                'host_enrollment_token' => fn () => $request->session()->get('host_enrollment_token'),
                'host_enrollment_host_id' => fn () => $request->session()->get('host_enrollment_host_id'),
            ],
        ];
    }
}
