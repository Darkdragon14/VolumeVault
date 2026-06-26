<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function create(): Response
    {
        if (! User::exists()) {
            return Inertia::location(route('onboarding.create'));
        }

        return Inertia::render('Auth/Login', [
            'mailResetEnabled' => ! in_array(config('mail.default'), ['array', 'log'], true),
        ]);
    }

    public function store(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'The provided credentials do not match our records.',
            ]);
        }

        // Defer the login until the second factor is satisfied: stash just the
        // user id (and the remember preference) so the challenge can complete it.
        if ($user->hasTwoFactorEnabled()) {
            // 2FA accounts never get a persistent "remember me" recaller: every
            // new session must clear the challenge again.
            $request->session()->put('login.id', $user->getKey());

            return redirect()->route('two-factor.challenge');
        }

        Auth::login($user, $request->boolean('remember'));

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route(User::exists() ? 'login' : 'onboarding.create');
    }
}
