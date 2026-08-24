<?php

declare(strict_types=1);

namespace App\Api\Account\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request payload for POST /api/v1/auth/change-password.
 *
 * Validation lives here, not in the controller and not in the service: the service is
 * entitled to assume its input is structurally valid, and #[MapRequestPayload] turns a
 * violation into a 422 problem+json before the controller runs.
 *
 * The structural password policy (length, username resemblance, repetition, breach) is
 * enforced once, in AssertPasswordAcceptableService, so the rules cannot drift between the
 * flows that create, reset and change a password. The DTO therefore only asserts presence.
 */
final readonly class ChangePasswordRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $currentPassword = '',
        #[Assert\NotBlank]
        public string $newPassword = '',
    ) {
    }
}
