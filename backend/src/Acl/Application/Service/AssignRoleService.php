<?php

declare(strict_types=1);

namespace App\Acl\Application\Service;

use App\Acl\Domain\AclException;
use App\Acl\Domain\Role;
use App\Acl\Domain\RoleRepository;
use App\Acl\Domain\SubjectRepository;
use App\Audit\Application\AuditFacade;
use App\Shared\Domain\SecurityEventType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Gives a user a role.
 *
 * Guarded by `permission.grant` rather than `user.update`, which looks inconsistent with the
 * resource being modified until you notice what assigning a role actually is: attaching
 * ROLE_SUPER_ADMIN to somebody is the highest-privilege write in the system, and it has nothing
 * to do with editing their profile. Someone who may correct an email address should not thereby
 * be able to make anyone an administrator.
 */
final readonly class AssignRoleService
{
    public function __construct(
        private RoleRepository $roles,
        private SubjectRepository $subjects,
        private InvalidateAclCachesService $invalidate,
        private AuditFacade $audit,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(Uuid $userId, string $roleName, Uuid $grantedBy): Role
    {
        $role = $this->roles->findByName($roleName);

        if (!$role instanceof Role) {
            throw AclException::noSuchRole($roleName);
        }

        $this->subjects->assignRole($userId, $role);
        $this->em->flush();

        // Before the response is built, not after: the caller may read the result back in this
        // same request, and the memoised pre-grant answer would still be sitting there.
        $this->invalidate->forUsers([$userId]);

        $this->audit->record(SecurityEventType::RoleAssigned, $grantedBy, [
            'subjectId' => $userId->toRfc4122(),
            'role' => $role->name(),
        ]);

        return $role;
    }
}
