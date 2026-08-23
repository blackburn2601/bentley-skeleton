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
 * @responsibility Takes a role away from a user.
 *
 * The half of role assignment that has to work, because it is the security-relevant one. A
 * grant that fails to apply is an inconvenience; a revocation that fails to apply is the
 * access someone was supposed to lose, and it fails silently — which is why the cache
 * invalidation below is not optional (ADR-0011, ADR-0021).
 */
final readonly class RevokeRoleService
{
    public function __construct(
        private RoleRepository $roles,
        private SubjectRepository $subjects,
        private InvalidateAclCachesService $invalidate,
        private AuditFacade $audit,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(Uuid $userId, string $roleName, Uuid $revokedBy): Role
    {
        $role = $this->roles->findByName($roleName);

        if (!$role instanceof Role) {
            throw AclException::noSuchRole($roleName);
        }

        // Idempotent: revoking a role the user never held is not an error, it is a request for
        // a state that already holds.
        $this->subjects->revokeRole($userId, $role);
        $this->em->flush();

        $this->invalidate->forUsers([$userId]);

        $this->audit->record(SecurityEventType::RoleRevoked, $revokedBy, [
            'subjectId' => $userId->toRfc4122(),
            'role' => $role->name(),
        ]);

        return $role;
    }
}
