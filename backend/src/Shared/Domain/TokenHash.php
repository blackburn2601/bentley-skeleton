<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * A hashed bearer secret, and the only form in which one is ever stored.
 *
 * Refresh tokens, email-verification tokens and password-reset tokens are all bearer
 * secrets: whoever holds the value is the holder. Storing them in plaintext means a single
 * SELECT on a database backup hands over every live session and every pending reset.
 *
 * SHA-256 rather than a password hash, deliberately. These values are 256 bits of CSPRNG
 * output, not user-chosen, so there is nothing to brute-force and no need for a slow KDF —
 * and lookup happens on every refresh, where argon2id's cost would be paid per request for
 * no security gain. Password hashing is a different problem with a different answer
 * (argon2id, see the password hasher config).
 */
final readonly class TokenHash
{
    private function __construct(public string $value)
    {
    }

    /** Hash a plaintext token for storage or lookup. */
    public static function of(string $plaintext): self
    {
        return new self(hash('sha256', $plaintext));
    }

    /** Rebuild from a stored hash — no hashing, this value is already hashed. */
    public static function fromStorage(string $hash): self
    {
        return new self($hash);
    }

    /**
     * Constant-time comparison.
     *
     * Both sides are hashes of the same length here, so a timing signal would be weak — but
     * the cost of doing this correctly is zero and the habit is what matters.
     */
    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }
}
