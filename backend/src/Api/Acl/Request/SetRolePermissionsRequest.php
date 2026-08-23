<?php

declare(strict_types=1);

namespace App\Api\Acl\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The body of PUT /api/v1/admin/roles/{id}/permissions — the whole set, not a delta.
 */
final readonly class SetRolePermissionsRequest
{
    public function __construct(
        /** @var list<string> */
        #[Assert\All([new Assert\NotBlank(), new Assert\Length(max: 100)])]
        #[Assert\Count(max: 200)]
        public array $permissions = [],
    ) {
    }
}
