<?php

declare(strict_types=1);

namespace App\Acl\Application\Service;

use App\Acl\Application\AclFacade;
use App\Acl\Domain\AclException;
use App\Acl\Domain\Permission;
use App\Acl\Domain\PermissionRepository;
use App\Acl\Domain\Role;
use App\Acl\Domain\RoleRepository;
use App\Audit\Application\AuditFacade;
use App\Shared\Domain\SecurityEventType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Replaces the permission set carried by one role.
 *
 * Guarded by `permission.grant`, not `role.update`. Attaching `user.delete` to a role is
 * granting `user.delete` to everyone who holds it, now and in future — the highest-privilege
 * write in the system. Someone trusted to rename a role is not thereby trusted to make it
 * administrator-equivalent.
 *
 * The escalation ceiling below is what stops `permission.grant` from being a synonym for
 * ROLE_SUPER_ADMIN: without it, a caller attaches anything they like to a role they already
 * hold, and awards themselves the rest of the system in two requests.
 */
final readonly class SetRolePermissionsService
{
    public function __construct(
        private RoleRepository $roles,
        private PermissionRepository $permissions,
        private AclFacade $acl,
        private InvalidateAclCachesService $invalidate,
        private AuditFacade $audit,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @param list<string> $permissionNames
     */
    public function __invoke(Uuid $roleId, array $permissionNames, Uuid $grantedBy): Role
    {
        $role = $this->roles->findById($roleId);

        if (!$role instanceof Role) {
            throw AclException::noSuchRole($roleId->toRfc4122());
        }

        if (Role::SUPER_ADMIN === $role->name()) {
            throw AclException::superAdminHasNoPermissionList();
        }

        $wanted = $this->resolve($permissionNames);
        $this->refuseEscalation($wanted, $grantedBy);

        $before = array_map(static fn (Permission $p): string => $p->name(), $role->permissions());

        foreach ($role->permissions() as $existing) {
            $role->revoke($existing);
        }

        foreach ($wanted as $permission) {
            $role->grant($permission);
        }

        $this->em->flush();
        $this->invalidate->forRole($role);

        $this->audit->record(SecurityEventType::PermissionGranted, $grantedBy, [
            'action' => 'set_role_permissions',
            'role' => $role->name(),
            'before' => $before,
            'after' => array_values($permissionNames),
        ]);

        return $role;
    }

    /**
     * @param list<string> $names
     *
     * @return list<Permission>
     */
    private function resolve(array $names): array
    {
        $resolved = [];

        foreach (array_unique($names) as $name) {
            $permission = $this->permissions->findByName($name);

            if (!$permission instanceof Permission) {
                throw AclException::noSuchPermission($name);
            }

            $resolved[] = $permission;
        }

        return $resolved;
    }

    /**
     * @param list<Permission> $wanted
     */
    private function refuseEscalation(array $wanted, Uuid $grantedBy): void
    {
        foreach ($wanted as $permission) {
            // isGranted with no resource answers the RBAC question, which is the right one:
            // "may this caller do it in general?" A super admin short-circuits to true, so the
            // ceiling never obstructs the person who could bypass it anyway.
            if (!$this->acl->isGranted($grantedBy, $permission->name())) {
                throw AclException::cannotGrantWhatYouDoNotHold($permission->name());
            }
        }
    }
}
