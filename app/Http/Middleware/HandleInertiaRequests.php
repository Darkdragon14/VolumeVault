<?php

namespace App\Http\Middleware;

use App\Models\User;
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
                    'theme' => $request->user()->theme ?: User::DEFAULT_THEME,
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
                'locale' => $request->user()?->locale ?? User::DEFAULT_LOCALE,
                'locales' => User::SUPPORTED_LOCALES,
                'theme' => $request->user()?->theme ?? User::DEFAULT_THEME,
                'themes' => User::SUPPORTED_THEMES,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'api_token' => fn () => $request->session()->get('api_token'),
            ],
        ];
    }
}
