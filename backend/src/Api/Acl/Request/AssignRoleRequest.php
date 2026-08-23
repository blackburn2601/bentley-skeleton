<?php

declare(strict_types=1);

namespace App\Api\Acl\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The body of POST /api/v1/admin/users/{id}/roles.
 */
final readonly class AssignRoleRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $role = '',
    ) {
    }
}
