<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TwoFactor\TwoFactorAuthenticator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorChallengeController extends Controller
{
    public function __construct(private readonly TwoFactorAuthenticator $authenticator) {}

    public function create(Request $request): Response|\Illuminate\Http\RedirectResponse
    {
        if (! $request->session()->has('login.id')) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    public function store(Request $request)
    {
        $request->validate([
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $userId = $request->session()->get('login.id');

        if (! $userId || ! ($user = User::find($userId)) || ! $user->hasTwoFactorEnabled()) {
            $request->session()->forget(['login.id', 'login.remember']);

            return redirect()->route('login');
        }

        $code = $request->string('code')->trim()->value();
        $recoveryCode = $request->string('recovery_code')->trim()->value();

        $passed = match (true) {
            filled($recoveryCode) => $this->authenticator->consumeRecoveryCode($user, $recoveryCode),
            filled($code) => $this->authenticator->verify($user->two_factor_secret, $code),
            default => false,
        };

        if (! $passed) {
            throw ValidationException::withMessages([
                'code' => 'The provided two-factor code is invalid.',
            ]);
        }

        Auth::login($user, (bool) $request->session()->get('login.remember', false));

        $request->session()->forget(['login.id', 'login.remember']);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
