<?php

declare(strict_types=1);

namespace App\Api\Acl\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The body of POST /api/v1/admin/groups.
 */
final readonly class CreateGroupRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        #[Assert\Regex(pattern: '/^[a-z0-9][a-z0-9-]*$/', message: 'A group name may use lowercase letters, digits and hyphens.')]
        public string $name = '',
        #[Assert\Length(max: 255)]
        public ?string $description = null,
    ) {
    }
}
