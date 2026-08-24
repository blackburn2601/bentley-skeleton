<?php

declare(strict_types=1);

namespace App\Api\Account\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The body of PUT /api/v1/admin/users/{id}/mfa/required.
 *
 * A single boolean: `true` enforces MFA on the user (they must enroll before the next login that
 * offers it), `false` lifts the requirement. The target user is in the path, not the body. The
 * field is `NotNull` rather than `NotBlank` because `false` is a legitimate, meaningful value —
 * `NotBlank` would reject it.
 */
final readonly class AdminRequireTwoFactorRequest
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Type('boolean')]
        public bool $required,
    ) {
    }
}
