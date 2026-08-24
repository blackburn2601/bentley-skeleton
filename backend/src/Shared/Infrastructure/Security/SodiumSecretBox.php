<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use App\Shared\Domain\SecretDecryptionFailed;
use App\Shared\Domain\SecretEncryptor;
use InvalidArgumentException;

/**
 * libsodium `crypto_secretbox` (XSalsa20-Poly1305) for the TOTP secret at rest.
 *
 * Authenticated encryption: the ciphertext carries a Poly1305 tag, so a tampered value fails
 * to open rather than decrypting to something wrong. A fresh 24-byte nonce per encryption,
 * prefixed to the ciphertext, so the decryptor does not have to store or track one.
 *
 * The key comes from `TOTP_SECRET_KEY` — 32 bytes, base64. The construction fails fast on a
 * missing or short key rather than silently weakening to a derived one: a silently-derived
 * key is one misconfiguration away from "every environment shares the same encryption key".
 */
final readonly class SodiumSecretBox implements SecretEncryptor
{
    private const int NONCE_BYTES = \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

    /** @var non-empty-string 32 raw key bytes */
    private string $key;

    public function __construct(string $keyBase64)
    {
        $decoded = base64_decode($keyBase64, true);

        if (false === $decoded || '' === $decoded || \SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($decoded)) {
            // Fail at the boundary: this runs in the container builder, so a misconfigured
            // deploy refuses to start instead of encrypting secrets under a fallback key.
            throw new InvalidArgumentException('TOTP_SECRET_KEY must be 32 bytes, base64-encoded (SODIUM_CRYPTO_SECRETBOX_KEYBYTES).');
        }

        $this->key = $decoded;
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return base64_encode($nonce.$ciphertext);
    }

    public function decrypt(string $ciphertext): string
    {
        $raw = base64_decode($ciphertext, true);

        if (false === $raw || \strlen($raw) < self::NONCE_BYTES) {
            throw SecretDecryptionFailed::forSecret();
        }

        $nonce = substr($raw, 0, self::NONCE_BYTES);
        $boxed = substr($raw, self::NONCE_BYTES);
        $plain = sodium_crypto_secretbox_open($boxed, $nonce, $this->key);

        if (false === $plain) {
            throw SecretDecryptionFailed::forSecret();
        }

        return $plain;
    }
}
