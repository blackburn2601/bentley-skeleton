<?php

declare(strict_types=1);

namespace App\Api\Acl\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The body of PUT /api/v1/admin/groups/{id}/members — the whole list, not a delta.
 */
final readonly class SetGroupMembersRequest
{
    public function __construct(
        /** @var list<string> */
        #[Assert\All([new Assert\Uuid()])]
        #[Assert\Count(max: 500)]
        public array $members = [],
    ) {
    }
}
