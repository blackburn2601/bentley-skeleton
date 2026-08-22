<?php

declare(strict_types=1);

namespace App\Acl\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * Resolves who a caller is, for authorization purposes.
 *
 * Separate from the Account context's user repository on purpose: this reads role
 * assignments and group memberships, which Acl owns (see UserRole), and returns them
 * flattened into the shape the resolver needs.
 */
interface SubjectRepository
{
    /**
     * Direct roles, group memberships, and roles inherited through those groups, flattened.
     */
    public function subjectSetFor(Uuid $userId): SubjectSet;

    /** @return list<UserGroup> */
    public function groupsOf(Uuid $userId): array;

    /** @return list<Role> */
    public function rolesOf(Uuid $userId): array;

    public function assignRole(Uuid $userId, Role $role): void;

    public function revokeRole(Uuid $userId, Role $role): void;

    public function addToGroup(Uuid $userId, UserGroup $group): void;

    public function removeFromGroup(Uuid $userId, UserGroup $group): void;

    /** @return list<Uuid> user ids in this group — needed to invalidate their ACL caches */
    public function memberIdsOf(UserGroup $group): array;
}
