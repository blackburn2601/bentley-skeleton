<?php

declare(strict_types=1);

namespace App\Acl\Application\Service;

use App\Acl\Domain\AclException;
use App\Acl\Domain\Role;
use App\Acl\Domain\RoleRepository;
use App\Audit\Application\AuditFacade;
use App\Shared\Domain\SecurityEventType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Removes a role that is safe to delete.
 *
 * Note the order: the holders are collected and their caches invalidated BEFORE the delete.
 * `user_role` cascades on delete, so afterwards there is no way to discover who was affected —
 * and they would keep their cached decisions for the life of the cache, holding access through
 * a role that no longer exists.
 */
final readonly class DeleteRoleService
{
    public function __construct(
        private RoleRepository $roles,
        private InvalidateAclCachesService $invalidate,
        private AuditFacade $audit,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(Uuid $roleId, Uuid $deletedBy): string
    {
        $role = $this->roles->findById($roleId);

        if (!$role instanceof Role) {
            throw AclException::noSuchRole($roleId->toRfc4122());
        }

        if (\in_array($role->name(), [Role::SUPER_ADMIN, Role::DEFAULT_USER], true)) {
            throw AclException::roleIsBaseline($role->name());
        }

        $name = $role->name();

        // Before the delete, while the holders are still discoverable.
        $this->invalidate->forRole($role);

        $this->roles->remove($role);
        $this->em->flush();

        $this->audit->record(SecurityEventType::RoleRevoked, $deletedBy, [
            'action' => 'delete_role',
            'role' => $name,
        ]);

        return $name;
    }
}
