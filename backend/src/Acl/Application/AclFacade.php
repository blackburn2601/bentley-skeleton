<?php

declare(strict_types=1);

namespace App\Acl\Application;

use App\Acl\Application\Service\AssignDefaultRoleService;
use App\Acl\Domain\PermissionCatalog;
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
 * Kept deliberately narrow: reading decisions, reading subject membership, and the one
 * write below. Granting and revoking permissions goes through the admin services, which also
 * have to write audit events.
 */
final readonly class AclFacade
{
    public function __construct(
        private PermissionResolver $resolver,
        private SubjectRepository $subjects,
        private AssignDefaultRoleService $assignDefaultRole,
    ) {
    }

    /**
     * Give a newly registered user the baseline role.
     *
     * The one write on this facade, and it is here because the alternative is worse: either
     * Account reaches into Acl's tables to assign a role — which is the coupling INV-02
     * exists to prevent — or a user registers with no grants at all and cannot read their own
     * profile. Acl decides what "default" means; Account only says "this person is new".
     */
    public function assignDefaultRole(Uuid $userId): void
    {
        ($this->assignDefaultRole)($userId);
    }

    /**
     * @param object|class-string|null $resource see PermissionResolver::isGranted()
     */
    public function isGranted(Uuid $userId, string $permission, object|string|null $resource = null): bool
    {
        return $this->resolver->isGranted($userId, $permission, $resource);
    }

    /**
     * Why a decision came out the way it did — for the admin explain endpoint.
     *
     * @param object|class-string|null $resource see PermissionResolver::isGranted()
     */
    public function explain(Uuid $userId, string $permission, object|string|null $resource = null): PermissionDecision
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

    /**
     * Class-level permissions this user holds, for the SPA to hide controls with.
     *
     * Deliberately CLASS-level only. Object-level grants are not enumerable — "may read this
     * one document" cannot be expressed as a permission name — and reporting the catalogue
     * entry for it would tell the UI the user may read every document of that type. The
     * result is advisory in any case (INV-16); the server re-checks every request.
     *
     * Lives here rather than in the caller because enumerating the catalogue means reading
     * PermissionCatalog, which is Acl's own vocabulary and not something another context may
     * import (INV-02).
     *
     * @return list<string>
     */
    public function classLevelPermissionsOf(Uuid $userId): array
    {
        $granted = [];

        foreach (PermissionCatalog::all() as $permission) {
            if ($this->resolver->isGranted($userId, $permission)) {
                $granted[] = $permission;
            }
        }

        return $granted;
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
