<?php

declare(strict_types=1);

namespace App\Account\Infrastructure\Security;

use App\Account\Application\Totp;
use OTPHP\TOTP as OtpTotpLib;

/**
 * spomky-labs/otphp implementation of {@see Totp} (ADR-0026).
 *
 * HMAC-SHA1, 6 digits, 30-second period — the values every authenticator app defaults to, so
 * a Microsoft/Google Authenticator scan works with no per-app configuration. A leeway of 1
 * window on verify covers clock drift between the app and the server.
 *
 * The library class is aliased because PHP class names are case-insensitive: the unqualified
 * `TOTP` would collide with the {@see Totp} port imported above, which on a case-insensitive
 * filesystem is the same name.
 */
final readonly class OtpTotp implements Totp
{
    public function __construct(private string $issuer)
    {
    }

    public function generateSecret(): string
    {
        return OtpTotpLib::create()->getSecret();
    }

    public function provisioningUri(string $label, string $secret): string
    {
        // otphp rejects empty strings; both values are non-empty by construction (the username is
        // a stored account identifier, the secret was just generated), so the asserts only
        // narrow the type for the library call.
        \assert('' !== $label && '' !== $secret);

        $totp = OtpTotpLib::createFromSecret($secret);
        $totp->setLabel($label);
        \assert('' !== $this->issuer);
        $totp->setIssuer($this->issuer);

        return $totp->getProvisioningUri();
    }

    public function verify(string $secret, string $code): bool
    {
        \assert('' !== $secret && '' !== $code);

        // Leeway 1 = ±one 30s window, the standard tolerance for clock drift. otphp's verify
        // is constant-time internally.
        return OtpTotpLib::createFromSecret($secret)->verify($code, null, 1);
    }
}
