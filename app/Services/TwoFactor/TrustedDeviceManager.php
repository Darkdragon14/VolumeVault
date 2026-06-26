<?php

namespace App\Services\TwoFactor;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

class TrustedDeviceManager
{
    public const COOKIE = 'vv_2fa_device';

    public const DAYS = 30;

    /**
     * True when the request carries a cookie matching a non-expired trusted
     * device for this user. Refreshes last_used_at as a side effect. This only
     * ever waives the 2FA challenge — the caller still verifies the password.
     */
    public function hasValidDevice(Request $request, User $user): bool
    {
        $token = $request->cookie(self::COOKIE);

        if (! is_string($token) || $token === '') {
            return false;
        }

        $device = $user->twoFactorTrustedDevices()
            ->where('token', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();

        if (! $device) {
            return false;
        }

        $device->forceFill(['last_used_at' => now()])->save();

        return true;
    }

    /**
     * Remember this browser for the user: persist a hashed token and queue the
     * matching 30-day cookie on the response.
     */
    public function trust(User $user, Request $request): void
    {
        $token = Str::random(40);

        $user->twoFactorTrustedDevices()->create([
            'token' => hash('sha256', $token),
            'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
            'expires_at' => now()->addDays(self::DAYS),
            'last_used_at' => now(),
        ]);

        Cookie::queue(Cookie::make(
            name: self::COOKIE,
            value: $token,
            minutes: self::DAYS * 24 * 60,
            httpOnly: true,
            sameSite: 'lax',
        ));
    }

    /**
     * Revoke every trusted device for the user (e.g. when 2FA is disabled or
     * reset) and drop the cookie on the current browser.
     */
    public function clearForUser(User $user): void
    {
        $user->twoFactorTrustedDevices()->delete();

        Cookie::queue(Cookie::forget(self::COOKIE));
    }
}
