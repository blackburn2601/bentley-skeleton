<?php

declare(strict_types=1);

namespace App\Api\Account\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The body of POST /api/v1/auth/mfa/verify.
 *
 * Six digits only — the value every TOTP authenticator displays. The user is identified by the
 * signed `sub` of the challenge cookie, so no username travels in the body (ADR-0026
 * anti-enumeration). Validation here means the service can assume a structurally valid code.
 */
final readonly class VerifyTwoFactorRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Regex('/^[0-9]{6}$/')]
        public string $code = '',
    ) {
    }
}
