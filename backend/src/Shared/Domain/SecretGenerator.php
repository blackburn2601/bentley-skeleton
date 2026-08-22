<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Cryptographically secure random secrets.
 *
 * A port so tests can make token values predictable. Never implement it with rand(),
 * mt_rand() or uniqid(): those are predictable, and a predictable refresh token is a
 * forgeable session.
 */
interface SecretGenerator
{
    /**
     * @param int<16, 128> $bytes 32 bytes = 256 bits, the default for bearer tokens
     *
     * @return non-empty-string URL-safe, unpadded base64
     */
    public function generate(int $bytes = 32): string;

    /** A numeric code for MFA recovery and similar human-typed secrets. */
    public function generateNumericCode(int $digits = 8): string;
}
