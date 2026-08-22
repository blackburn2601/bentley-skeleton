<?php

declare(strict_types=1);

namespace App\Acl\Application\Service;

use App\Acl\Domain\PermissionCatalog;
use App\Acl\Domain\PermissionRepository;
use App\Acl\Domain\Role;
use App\Acl\Domain\RoleRepository;
use App\Shared\Domain\Clock;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @responsibility Guarantees the roles the application cannot function without exist.
 */
final readonly class EnsureBaselineRolesService
{
    /**
     * What a user may do to their own account, and nothing more.
     *
     * Note what is absent: no `user.read`, which is administrative and covers OTHER people's
     * accounts. Conflating "read my profile" with "read any profile" is the single easiest way
     * to turn a baseline role into a privilege escalation.
     *
     * @var list<string>
     */
    private const array DEFAULT_USER_PERMISSIONS = [
        PermissionCatalog::ACCOUNT_READ,
        PermissionCatalog::ACCOUNT_UPDATE,
        PermissionCatalog::ACCOUNT_DELETE,
        PermissionCatalog::ACCOUNT_EXPORT,
    ];

    public function __construct(
        private RoleRepository $roles,
        private PermissionRepository $permissions,
        private Clock $clock,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * Idempotent, and additive only.
     *
     * Permissions are added to the baseline role if missing, never removed: an operator may
     * have deliberately granted more, and a sync command that silently revoked it would be a
     * privilege change nobody asked for.
     *
     * @return array{created: list<string>, granted: list<string>}
     */
    public function __invoke(): array
    {
        $created = [];
        $granted = [];

        $superAdmin = $this->roles->findByName(Role::SUPER_ADMIN);

        if (null === $superAdmin) {
            // Holds no permissions: it short-circuits the resolver entirely (ADR-0003), and
            // attaching permissions would imply the list means something.
            $this->roles->save(new Role(
                Role::SUPER_ADMIN,
                $this->clock->now(),
                'Bypasses every permission check. Every use is audited.',
            ));
            $created[] = Role::SUPER_ADMIN;
        }

        $defaultUser = $this->roles->findByName(Role::DEFAULT_USER);

        if (null === $defaultUser) {
            $defaultUser = new Role(
                Role::DEFAULT_USER,
                $this->clock->now(),
                'Held by every registered user. Covers their own account only.',
            );
            $this->roles->save($defaultUser);
            $created[] = Role::DEFAULT_USER;
        }

        $held = array_map(
            static fn (\App\Acl\Domain\Permission $p): string => $p->name(),
            $defaultUser->permissions(),
        );

        foreach (self::DEFAULT_USER_PERMISSIONS as $name) {
            if (\in_array($name, $held, true)) {
                continue;
            }

            $permission = $this->permissions->findByName($name);

            if (null === $permission) {
                continue;
            }

            $defaultUser->grant($permission);
            $granted[] = $name;
        }

        $this->em->flush();

        return ['created' => $created, 'granted' => $granted];
    }
}
