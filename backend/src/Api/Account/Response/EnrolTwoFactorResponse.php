<?php

declare(strict_types=1);

namespace App\Api\Account\Response;

use App\Account\Application\TotpEnrollment;

/**
 * What enrollment returns so the caller can add an authenticator (ADR-0026).
 *
 * The plaintext secret is here on purpose: an authenticator that cannot scan the QR must be
 * configured by typing it. It is shown once; the server keeps only the encrypted form, and the
 * secret never becomes live until the confirm step. The QR is a data URL so the SPA needs no
 * QR library — it drops straight into an `<img>`.
 */
final readonly class EnrolTwoFactorResponse
{
    public function __construct(
        public string $secret,
        public string $provisioningUri,
        public string $qrDataUrl,
    ) {
    }

    public static function from(TotpEnrollment $enrollment): self
    {
        return new self(
            $enrollment->secret,
            $enrollment->provisioningUri,
            $enrollment->qrDataUrl,
        );
    }
}
