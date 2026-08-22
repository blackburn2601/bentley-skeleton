<?php

declare(strict_types=1);

namespace App\Acl\Application\Service;

use App\Acl\Domain\Role;
use App\Acl\Domain\RoleRepository;
use App\Acl\Domain\SubjectRepository;
use App\Shared\Application\AclVersionProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Gives a newly registered user the baseline role every account needs.
 */
final readonly class AssignDefaultRoleService
{
    public function __construct(
        private RoleRepository $roles,
        private SubjectRepository $subjects,
        private AclVersionProvider $versions,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(Uuid $userId): void
    {
        $role = $this->roles->findByName(Role::DEFAULT_USER);

        if (null === $role) {
            // Registration must not fail because a baseline role was never seeded. The user
            // exists and can verify their address; an operator runs app:acl:sync-permissions
            // and the role attaches on next assignment.
            return;
        }

        $this->subjects->assignRole($userId, $role);
        $this->em->flush();

        // Every grant change bumps the version, or the permission stays absent from cached
        // decisions for as long as the ACL cache lives (ADR-0011).
        $this->versions->bumpAll([$userId]);
    }
}
