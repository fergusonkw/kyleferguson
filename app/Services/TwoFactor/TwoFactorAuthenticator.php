<?php

declare(strict_types=1);

namespace App\Services\TwoFactor;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Thin wrapper over the pragmarx/google2fa TOTP algorithm plus QR rendering.
 * Deliberately framework-free: this is the only 2FA dependency and it is not
 * an auth library — Sanctum/the hand-rolled login flow stay in control.
 */
final class TwoFactorAuthenticator
{
    public function __construct(private readonly Google2FA $engine) {}

    public function generateSecretKey(): string
    {
        return $this->engine->generateSecretKey();
    }

    /**
     * Verify a TOTP code, tolerating ±1 time step for clock drift.
     */
    public function verify(string $secret, string $code): bool
    {
        $code = trim($code);

        if ($code === '' || $secret === '') {
            return false;
        }

        return $this->engine->verifyKey($secret, $code, 1);
    }

    /**
     * The otpauth:// URI an authenticator app scans.
     */
    public function provisioningUri(string $holder, string $secret): string
    {
        return $this->engine->getQRCodeUrl(
            (string) config('app.name'),
            $holder,
            $secret,
        );
    }

    /**
     * Inline SVG QR for the provisioning URI (pure PHP — no imagick/gd).
     */
    public function qrCodeSvg(string $holder, string $secret): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle(192, 1),
            new SvgImageBackEnd,
        ));

        return $writer->writeString($this->provisioningUri($holder, $secret));
    }

    /**
     * Single-use recovery codes (format ABCD-EFGH, uppercase).
     *
     * @return list<string>
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        return collect(range(1, $count))
            ->map(fn (): string => Str::upper(Str::random(4).'-'.Str::random(4)))
            ->all();
    }
}
