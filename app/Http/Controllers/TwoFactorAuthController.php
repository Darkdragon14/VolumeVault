<?php

namespace App\Http\Controllers;

use App\Models\TwoFactorTrustedDevice;
use App\Services\TwoFactor\TrustedDeviceManager;
use App\Services\TwoFactor\TwoFactorAuthenticator;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TwoFactorAuthController extends Controller
{
    public function __construct(
        private readonly TwoFactorAuthenticator $authenticator,
        private readonly TrustedDeviceManager $trustedDevices,
    ) {}

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

        // Rotate the remember token so any browser remembered before 2FA was
        // enabled can no longer skip the challenge with its old recaller cookie.
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'remember_token' => Str::random(60),
        ])->save();

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
            'remember_token' => Str::random(60),
        ])->save();

        // Drop every trusted device and expire the cookie on this browser too.
        $this->trustedDevices->clearForUser($user);

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

    /**
     * Revoke a single trusted device. The next login from that browser will be
     * challenged again.
     */
    public function destroyDevice(Request $request, TwoFactorTrustedDevice $device)
    {
        abort_unless($device->user_id === (int) $request->user()->getKey(), 403);

        $isCurrent = $this->trustedDevices->currentDevice($request, $request->user())?->is($device) ?? false;

        $device->delete();

        if ($isCurrent) {
            $this->trustedDevices->forgetCookie();
        }

        return redirect()->route('profile.edit')->with('success', 'Trusted device removed.');
    }

    /**
     * Revoke every trusted device for the current user.
     */
    public function destroyDevices(Request $request)
    {
        $this->trustedDevices->clearForUser($request->user());

        return redirect()->route('profile.edit')->with('success', 'Trusted devices removed.');
    }
}
