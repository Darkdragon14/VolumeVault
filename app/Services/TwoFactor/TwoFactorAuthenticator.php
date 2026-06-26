<?php

namespace App\Services\TwoFactor;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorAuthenticator
{
    public const RECOVERY_CODE_COUNT = 8;

    public function __construct(private readonly Google2FA $google2fa) {}

    public function generateSecretKey(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    /**
     * Inline SVG QR code for the user's otpauth URI. Rendered server-side so the
     * page never reaches an external chart service (CSP-friendly).
     */
    public function qrCodeSvg(User $user, string $secret): string
    {
        $url = $this->google2fa->getQRCodeUrl(
            config('app.name'),
            $user->email,
            $secret,
        );

        $writer = new Writer(new ImageRenderer(new RendererStyle(192, 0), new SvgImageBackEnd));

        return $writer->writeString($url);
    }

    public function verify(string $secret, string $code): bool
    {
        return $this->google2fa->verifyKey($secret, $code);
    }

    /**
     * @return list<string>
     */
    public function generateRecoveryCodes(): array
    {
        return collect(range(1, self::RECOVERY_CODE_COUNT))
            ->map(fn () => Str::random(10).'-'.Str::random(10))
            ->all();
    }

    /**
     * Validate a recovery code and, on a match, consume it (single use) by
     * persisting the remaining codes. Returns false when the code is unknown.
     */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];

        foreach ($codes as $stored) {
            if (hash_equals($stored, $code)) {
                $user->two_factor_recovery_codes = array_values(
                    array_filter($codes, fn ($value) => ! hash_equals($value, $code)),
                );
                $user->save();

                return true;
            }
        }

        return false;
    }
}
