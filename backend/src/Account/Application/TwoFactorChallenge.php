<?php

declare(strict_types=1);

namespace App\Account\Application;

/**
 * A half-authenticated session: the password checked out, the second factor has not.
 *
 * Carries only the challenge access token — no refresh token, no CSRF value — because a
 * refresh cookie issued before the second factor is verified would bypass MFA for its whole
 * 30-day lifetime (ADR-0026). The challenge token reuses the access-cookie slot and expires
 * in ~120 s, so an abandoned challenge clears itself.
 */
final readonly class TwoFactorChallenge
{
    public function __construct(
        public string $userId,
        public string $username,
        public string $accessToken,
        public int $accessTtlSeconds,
    ) {
    }
}
