<?php

declare(strict_types=1);

namespace App\Api\Account\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The body of POST /api/v1/admin/users.
 *
 * No password field, deliberately: the new user sets their own through the link this creates
 * (see CreateUserService). An administrator who could type a password would know it.
 */
final readonly class CreateUserRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT)]
        #[Assert\Length(max: 254)]
        public string $email = '',
    ) {
    }
}
