<?php

declare(strict_types=1);

namespace App\Api\Account\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The body of PATCH /api/v1/admin/users/{id}.
 */
final readonly class UpdateUserRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT)]
        #[Assert\Length(max: 254)]
        public string $email = '',
    ) {
    }
}
