<?php

declare(strict_types=1);

namespace App\Acl\Application\Service;

use App\Acl\Domain\AclException;
use App\Acl\Domain\Role;
use App\Acl\Domain\RoleRepository;
use App\Audit\Application\AuditFacade;
use App\Shared\Domain\Clock;
use App\Shared\Domain\SecurityEventType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Creates a named role.
 *
 * The new role carries nothing. Permissions are attached separately, through
 * SetRolePermissionsService, which is where the escalation ceiling lives — creating a role is
 * harmless, filling it is not.
 */
final readonly class CreateRoleService
{
    public function __construct(
        private RoleRepository $roles,
        private AuditFacade $audit,
        private Clock $clock,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(string $name, ?string $description, Uuid $createdBy): Role
    {
        $name = trim($name);

        if ($this->roles->findByName($name) instanceof Role) {
            throw AclException::roleNameTaken($name);
        }

        $role = new Role($name, $this->clock->now(), $description);
        $this->roles->save($role);
        $this->em->flush();

        $this->audit->record(SecurityEventType::RoleAssigned, $createdBy, [
            'action' => 'create_role',
            'role' => $role->name(),
        ]);

        return $role;
    }
}
