<?php

declare(strict_types=1);

namespace App\Account\Application;

/**
 * What an enrollment returns so the caller can add an authenticator (ADR-0026).
 *
 * The plaintext secret travels here because the user may need to type it into an app that
 * cannot scan the QR. It is shown once; the server keeps only the encrypted form, and the
 * provisional secret never becomes live until a first code confirms the app captured it.
 */
final readonly class TotpEnrollment
{
    public function __construct(
        public string $secret,
        public string $provisioningUri,
        public string $qrDataUrl,
    ) {
    }
}
