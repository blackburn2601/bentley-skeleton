<?php

declare(strict_types=1);

namespace App\Acl\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * Everything a permission check considers a caller to "be", resolved once per request.
 *
 * A grant may target the user, one of their groups, or one of their roles, so every check
 * needs all three. Resolving them once and passing this around is what keeps a page that
 * renders fifty rows from issuing fifty identical membership queries.
 *
 * Roles here are *effective* roles: held directly plus inherited through group membership.
 * Flattening that once, here, means no other code has to remember that groups carry roles.
 *
 * This is also the extension point for tenancy (ADR-0014): a tenant would become one more
 * dimension of the subject set, not a change to the resolver's algorithm.
 */
final readonly class SubjectSet
{
    /**
     * @param list<Uuid>   $groupIds
     * @param list<Uuid>   $roleIds   effective roles: direct + via groups
     * @param list<string> $roleNames used for the ROLE_SUPER_ADMIN short-circuit and the JWT
     */
    public function __construct(
        public Uuid $userId,
        public array $groupIds = [],
        public array $roleIds = [],
        public array $roleNames = [],
    ) {
    }

    public function isSuperAdmin(): bool
    {
        return \in_array(Role::SUPER_ADMIN, $this->roleNames, true);
    }

    /**
     * The (type, id) pairs an `acl_entry` row may match.
     *
     * @return list<array{AclSubjectType, Uuid}>
     */
    public function pairs(): array
    {
        $pairs = [[AclSubjectType::User, $this->userId]];

        foreach ($this->groupIds as $groupId) {
            $pairs[] = [AclSubjectType::Group, $groupId];
        }

        foreach ($this->roleIds as $roleId) {
            $pairs[] = [AclSubjectType::Role, $roleId];
        }

        return $pairs;
    }
}
