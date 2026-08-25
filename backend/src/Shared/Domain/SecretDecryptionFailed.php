<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use RuntimeException;

/**
 * Thrown when a stored ciphertext cannot be decrypted — a wrong key (rotated
 * `TOTP_SECRET_KEY`), a tampered value, or a value that was never encrypted.
 *
 * A domain exception so the problem+json listener maps it consistently. It surfaces as a
 * server error rather than an authentication failure: a secret that will not decrypt is an
 * operational condition, not something the caller can fix.
 */
final class SecretDecryptionFailed extends RuntimeException
{
    public static function forSecret(): self
    {
        return new self('A stored secret could not be decrypted.');
    }
}
