<?php

declare(strict_types=1);

namespace App\Acl\Application;

use App\Acl\Domain\PermissionDecision;
use App\Acl\Domain\Role;
use App\Acl\Domain\SubjectRepository;
use App\Acl\Domain\UserGroup;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Exposes the Acl context to other contexts as a single narrow surface.
 *
 * The Acl context's front door.
 *
 * Every other context calls the ACL through this and nothing else (INV-02). The value is not
 * indirection for its own sake — it is that `grep -rn AclFacade src/` lists every place in
 * the codebase that depends on authorization. Without it, that question has no cheap answer,
 * and the first "just this once" import into Acl's internals never gets reviewed.
 *
 * Kept deliberately narrow: reading decisions and reading subject membership. Mutating
 * grants goes through the admin services, which also have to write audit events.
 */
final readonly class AclFacade
{
    public function __construct(
        private PermissionResolver $resolver,
        private SubjectRepository $subjects,
    ) {
    }

    public function isGranted(Uuid $userId, string $permission, ?object $resource = null): bool
    {
        return $this->resolver->isGranted($userId, $permission, $resource);
    }

    /** Why a decision came out the way it did — for the admin explain endpoint. */
    public function explain(Uuid $userId, string $permission, ?object $resource = null): PermissionDecision
    {
        return $this->resolver->explain($userId, $permission, $resource);
    }

    /**
     * Symfony role names for this user, direct and inherited through groups.
     *
     * Used when minting an access token. Note what is NOT here: the permission list. Roles go
     * in the JWT, permissions never do (ADR-0011) — that is what makes a revoked grant take
     * effect on the next request instead of when the token expires.
     *
     * @return list<string>
     */
    public function roleNamesOf(Uuid $userId): array
    {
        return $this->subjects->subjectSetFor($userId)->roleNames;
    }

    /** @return list<Role> */
    public function rolesOf(Uuid $userId): array
    {
        return $this->subjects->rolesOf($userId);
    }

    /** @return list<UserGroup> */
    public function groupsOf(Uuid $userId): array
    {
        return $this->subjects->groupsOf($userId);
    }
}
