<?php

declare(strict_types=1);

namespace App\Api\Account\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The body of POST /api/v1/account/mfa/confirm.
 *
 * The first TOTP code from the authenticator the caller just scanned, which proves the app
 * captured the provisional secret. Six digits, like the verify endpoint.
 */
final readonly class ConfirmTwoFactorRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Regex('/^[0-9]{6}$/')]
        public string $code = '',
    ) {
    }
}
