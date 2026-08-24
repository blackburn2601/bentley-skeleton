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

    /** A numeric code for human-typed, read-aloud secrets. */
    public function generateNumericCode(int $digits = 8): string;

    /**
     * A password an administrator hands over once and a user types in.
     *
     * Distinct from {@see generate()}: that returns a url-safe base64 string which is fine for
     * a cookie but wretched to read aloud across a counter. This returns a copyable string from
     * an unambiguous alphabet (no 0/O/1/l/I) long enough to clear the password policy.
     *
     * @return non-empty-string
     */
    public function generateTemporaryPassword(int $length = 16): string;
}
