<?php

declare(strict_types=1);

namespace App\Api\Acl\Response;

use App\Acl\Domain\Role;
use App\Acl\Domain\UserGroup;

/**
 * One group, as an administrator's client needs it.
 */
final readonly class SetGroupRolesResponse
{
    /**
     * @param list<string> $roles
     */
    private function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public array $roles,
    ) {
    }

    public static function from(UserGroup $group): self
    {
        return new self(
            $group->id()->toRfc4122(),
            $group->name(),
            $group->description(),
            array_map(static fn (Role $r): string => $r->name(), $group->roles()),
        );
    }
}
