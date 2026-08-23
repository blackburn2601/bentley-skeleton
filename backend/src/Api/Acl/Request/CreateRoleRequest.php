<?php

declare(strict_types=1);

namespace App\Api\Acl\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The body of POST /api/v1/admin/roles.
 */
final readonly class CreateRoleRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        #[Assert\Regex(pattern: '/^ROLE_[A-Z0-9_]+$/', message: 'A role name must look like ROLE_SOMETHING.')]
        public string $name = '',
        #[Assert\Length(max: 255)]
        public ?string $description = null,
    ) {
    }
}
