<?php

declare(strict_types=1);

namespace App\Account\Application;

/**
 * TOTP (RFC 6238) generation and verification (ADR-0026).
 *
 * A port so the authenticator-app algorithm is an infrastructure decision, and so a test can
 * substitute a verifier that accepts a known code without depending on the clock. The secret
 * the caller passes and receives is the raw base32 string the QR encodes; encryption at rest
 * is handled separately by {@see \App\Shared\Domain\SecretEncryptor}.
 */
interface Totp
{
    /** A fresh base32 secret for a new enrollment. */
    public function generateSecret(): string;

    /**
     * The `otpauth://totp/...` provisioning URI an authenticator app consumes from the QR.
     *
     * @param string $label the account name shown in the app (the username)
     */
    public function provisioningUri(string $label, string $secret): string;

    /**
     * Verify a 6-digit code against the secret, allowing the ±1 time window that
     * authenticator apps and clocks drifting apart need.
     */
    public function verify(string $secret, string $code): bool;
}
