<?php

declare(strict_types=1);

namespace App\Acl\Application\Service;

use App\Acl\Domain\AclException;
use App\Acl\Domain\Role;
use App\Acl\Domain\RoleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Applies an administrator's edits to one role's description.
 *
 * The name is not editable, by design. Role names are load-bearing — they are constants in
 * code, they are the `roles` claim in every access token, and Symfony matches on them — so
 * renaming one would silently change who is who while every existing token still carries the
 * old value.
 */
final readonly class UpdateRoleService
{
    public function __construct(
        private RoleRepository $roles,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(Uuid $roleId, ?string $description): Role
    {
        $role = $this->roles->findById($roleId);

        if (!$role instanceof Role) {
            throw AclException::noSuchRole($roleId->toRfc4122());
        }

        $role->describe($description);
        $this->em->flush();

        return $role;
    }
}
