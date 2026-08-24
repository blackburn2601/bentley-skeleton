<?php

declare(strict_types=1);

namespace App\Api\Account\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The body of PATCH /api/v1/admin/users/{id}.
 *
 * The username charset (`[A-Za-z0-9._-]`) is the workforce identity policy recorded in ADR-0024.
 */
final readonly class UpdateUserRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(min: 3, max: 64)]
        #[Assert\Regex('/^[A-Za-z0-9._-]+$/')]
        public string $username = '',
    ) {
    }
}
