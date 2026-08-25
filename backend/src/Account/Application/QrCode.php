<?php

declare(strict_types=1);

namespace App\Account\Application;

/**
 * Renders text as a QR data URL (ADR-0026).
 *
 * A port so the QR library is an infrastructure decision. The one use is encoding the TOTP
 * provisioning URI for the enrollment screen; returning a data URL keeps the secret handling
 * server-side and means the SPA needs no QR library of its own.
 */
interface QrCode
{
    /** @return non-empty-string a `data:image/png;base64,...` URL encoding the given text */
    public function dataUrlFor(string $text): string;
}
