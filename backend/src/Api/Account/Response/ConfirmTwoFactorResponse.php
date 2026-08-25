<?php

declare(strict_types=1);

namespace App\Api\Account\Response;

/**
 * The recovery codes a fresh enrollment mints (ADR-0026).
 *
 * Shown exactly once: the server keeps only their SHA-256 hashes, so once this response leaves
 * the caller is the only party who can read them. A lost authenticator is recovered by typing
 * one; each is single-use.
 *
 * @param list<string> $recoveryCodes
 */
final readonly class ConfirmTwoFactorResponse
{
    /**
     * @param list<string> $recoveryCodes
     */
    public function __construct(
        public array $recoveryCodes,
    ) {
    }

    /**
     * @param list<string> $recoveryCodes
     */
    public static function from(array $recoveryCodes): self
    {
        return new self($recoveryCodes);
    }
}
