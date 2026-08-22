<?php

declare(strict_types=1);

namespace App\Account\Domain;

use DateInterval;

enum TokenPurpose: string
{
    case VerifyEmail = 'verify_email';
    case ResetPassword = 'reset_password';

    /**
     * How long a token of this purpose stays valid.
     *
     * Reset is deliberately much shorter than verification: it grants immediate account
     * takeover to whoever holds it, whereas a verification link only confirms an address the
     * user already controls.
     */
    public function ttl(): DateInterval
    {
        return match ($this) {
            self::VerifyEmail => new DateInterval('P1D'),
            self::ResetPassword => new DateInterval('PT30M'),
        };
    }
}
