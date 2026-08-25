<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Symmetric authenticated encryption for secrets stored at rest.
 *
 * The TOTP secret is the use case: it must be readable back to verify a code, so it cannot
 * be hashed the way a bearer token is. It is encrypted with a key the application holds, so
 * a database dump alone is not enough to impersonate a second factor.
 *
 * A port because the mechanism (libsodium here) is an infrastructure decision, and so a test
 * can substitute a no-op box without depending on the extension.
 */
interface SecretEncryptor
{
    /** @return non-empty-string the ciphertext, encoded for storage (nonce prefixed) */
    public function encrypt(string $plaintext): string;

    /**
     * @param non-empty-string $ciphertext as produced by encrypt()
     *
     * @throws SecretDecryptionFailed if the key is wrong or the ciphertext was tampered with
     */
    public function decrypt(string $ciphertext): string;
}
