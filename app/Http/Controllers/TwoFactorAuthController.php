<?php

namespace App\Http\Controllers;

use App\Services\TwoFactor\TwoFactorAuthenticator;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TwoFactorAuthController extends Controller
{
    public function __construct(private readonly TwoFactorAuthenticator $authenticator) {}

    /**
     * Begin enrolment: generate a secret and recovery codes but leave the second
     * factor unconfirmed until the user proves they can produce a valid code.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return redirect()->route('profile.edit');
        }

        $user->forceFill([
            'two_factor_secret' => $this->authenticator->generateSecretKey(),
            'two_factor_recovery_codes' => $this->authenticator->generateRecoveryCodes(),
            'two_factor_confirmed_at' => null,
        ])->save();

        return redirect()->route('profile.edit');
    }

    /**
     * Confirm enrolment with the first valid TOTP code.
     */
    public function confirm(Request $request)
    {
        $request->validate(['code' => ['required', 'string']]);

        $user = $request->user();

        if ($user->hasTwoFactorEnabled() || ! $user->two_factor_secret) {
            return redirect()->route('profile.edit');
        }

        if (! $this->authenticator->verify($user->two_factor_secret, $request->string('code')->trim())) {
            throw ValidationException::withMessages([
                'code' => 'The provided code is invalid.',
            ]);
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return redirect()->route('profile.edit')
            ->with('success', 'Two-factor authentication enabled.')
            ->with('two_factor_show_recovery', true);
    }

    /**
     * Disable the second factor. An active (confirmed) factor requires the
     * current password; cancelling an unconfirmed setup does not.
     */
    public function destroy(Request $request)
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            $request->validate(['password' => ['required', 'current_password']]);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return redirect()->route('profile.edit')->with('success', 'Two-factor authentication disabled.');
    }

    /**
     * Issue a fresh set of recovery codes, invalidating the previous ones.
     */
    public function recoveryCodes(Request $request)
    {
        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            return redirect()->route('profile.edit');
        }

        $user->forceFill([
            'two_factor_recovery_codes' => $this->authenticator->generateRecoveryCodes(),
        ])->save();

        return redirect()->route('profile.edit')
            ->with('success', 'Recovery codes regenerated.')
            ->with('two_factor_show_recovery', true);
    }
}
