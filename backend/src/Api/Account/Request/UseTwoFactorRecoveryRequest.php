<?php

declare(strict_types=1);

namespace App\Api\Account\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The body of POST /api/v1/auth/mfa/recovery/verify.
 *
 * A recovery code is a 10-digit numeric string the enrollment screen showed once. Separators
 * are tolerated: the service strips everything that is not a digit before hashing, so a caller
 * who copies "123-456-7890" is not punished for the formatting. Like the TOTP verify, no
 * username travels in the body.
 */
final readonly class UseTwoFactorRecoveryRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $code = '',
    ) {
    }
}
