<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TwoFactor\TwoFactorAuthenticator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public const VALID_PER_PAGE_VALUES = [10, 20, 50, 100, 0];

    public function edit(Request $request, TwoFactorAuthenticator $authenticator): Response
    {
        $user = $request->user();

        // Enrolment is "pending" once a secret exists but no confirming code has
        // been entered yet. The QR/secret are only ever exposed in that window;
        // recovery codes additionally surface right after confirm/regenerate.
        $pending = $user->two_factor_secret && ! $user->hasTwoFactorEnabled();

        $props = [
            'profileUser' => $user->only(['id', 'name', 'email', 'locale', 'default_per_page']),
            'locales' => User::SUPPORTED_LOCALES,
            'perPageOptions' => self::VALID_PER_PAGE_VALUES,
            'twoFactorEnabled' => $user->hasTwoFactorEnabled(),
            'twoFactorPending' => $pending,
        ];

        if ($pending) {
            $props['twoFactorQrSvg'] = $authenticator->qrCodeSvg($user, $user->two_factor_secret);
            $props['twoFactorSecret'] = $user->two_factor_secret;
        }

        if ($pending || $request->session()->get('two_factor_show_recovery')) {
            $props['twoFactorRecoveryCodes'] = $user->two_factor_recovery_codes;
        }

        return Inertia::render('Profile/Edit', $props);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'locale' => ['required', 'string', Rule::in(User::SUPPORTED_LOCALES)],
            'default_per_page' => ['required', 'integer', Rule::in(self::VALID_PER_PAGE_VALUES)],
            'password' => ['nullable', 'confirmed', Password::defaults()],
        ]);

        if (! filled($data['password'] ?? null)) {
            unset($data['password']);
        }

        $user->update($data);

        return redirect()->route('profile.edit')->with('success', 'Profile updated.');
    }
}
