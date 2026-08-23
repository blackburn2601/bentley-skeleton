<?php

declare(strict_types=1);

namespace App\Api\Acl\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The body of PATCH /api/v1/admin/roles/{id}. Only the description is editable.
 */
final readonly class UpdateRoleRequest
{
    public function __construct(
        #[Assert\Length(max: 255)]
        public ?string $description = null,
    ) {
    }
}
