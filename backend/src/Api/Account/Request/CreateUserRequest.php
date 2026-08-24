<?php

declare(strict_types=1);

namespace App\Api\Account\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The body of POST /api/v1/admin/users.
 *
 * No password field, deliberately: the service generates a temporary password and hands it to
 * the administrator once (ADR-0024). An administrator who could type a password would know it.
 *
 * The username charset (`[A-Za-z0-9._-]`) is the workforce identity policy recorded in ADR-0024.
 */
final readonly class CreateUserRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(min: 3, max: 64)]
        #[Assert\Regex('/^[A-Za-z0-9._-]+$/')]
        public string $username = '',
    ) {
    }
}
