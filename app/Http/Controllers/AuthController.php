<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TwoFactor\TrustedDeviceManager;
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

    public function store(Request $request, TrustedDeviceManager $trustedDevices)
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

        if ($user->hasTwoFactorEnabled()) {
            // A trusted browser waives only the second factor (the password was
            // just verified above); otherwise defer the login to the challenge,
            // stashing just the user id. 2FA accounts never get a persistent
            // "remember me" recaller, so every new session is re-evaluated here.
            if (! $trustedDevices->hasValidDevice($request, $user)) {
                $request->session()->put('login.id', $user->getKey());

                return redirect()->route('two-factor.challenge');
            }
        }

        // Honor "remember me" only for non-2FA accounts; a trusted 2FA browser
        // still re-enters its password each session.
        Auth::login($user, ! $user->hasTwoFactorEnabled() && $request->boolean('remember'));

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
