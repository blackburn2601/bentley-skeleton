<?php

declare(strict_types=1);

namespace App\Fixtures;

use App\Account\Domain\User;
use App\Acl\Domain\AclEffect;
use App\Acl\Domain\AclEntry;
use App\Acl\Domain\AclSubjectType;
use App\Acl\Domain\GroupMembership;
use App\Acl\Domain\Permission;
use App\Acl\Domain\PermissionCatalog;
use App\Acl\Domain\Role;
use App\Acl\Domain\UserGroup;
use App\Acl\Domain\UserRole;
use App\Shared\Domain\Clock;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * A demo dataset that exercises every shape of grant the ACL supports.
 *
 * Not just "an admin and a user". The point of these fixtures is that someone reading them
 * learns how the authorization model is meant to be used — a role grant, a group grant, an
 * object-level grant, and an explicit deny carving an exception out of a broader allow — and
 * that the ACL tests have realistic data to run against.
 *
 * The password is the same for everyone and is printed at the end. This is demo data; it must
 * never be loaded into an environment that matters, which is why `make fixtures` is not part
 * of the production deploy path.
 */
final class AppFixtures extends Fixture
{
    public const string DEMO_PASSWORD = 'demo-password-not-for-real-use';

    public function __construct(
        private readonly PasswordHasherFactoryInterface $hashers,
        private readonly Clock $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();
        $hasher = $this->hashers->getPasswordHasher('app_account_password');

        $permissions = $this->permissions($manager, $now);
        $roles = $this->roles($manager, $now, $permissions);

        $admin = $this->user($manager, $hasher, 'admin@bentley.localhost', $now);
        $editor = $this->user($manager, $hasher, 'editor@bentley.localhost', $now);
        $viewer = $this->user($manager, $hasher, 'viewer@bentley.localhost', $now);

        $manager->flush();

        // --- role grants: the coarse layer -------------------------------------------
        $manager->persist(new UserRole($admin->id(), $roles['ROLE_SUPER_ADMIN'], $now));
        $manager->persist(new UserRole($admin->id(), $roles[Role::DEFAULT_USER], $now));
        $manager->persist(new UserRole($editor->id(), $roles[Role::DEFAULT_USER], $now));
        $manager->persist(new UserRole($viewer->id(), $roles[Role::DEFAULT_USER], $now));

        // --- a group carrying a role: how "everyone on this team" is expressed --------
        $support = new UserGroup('support', $now, 'Demo group. Carries the auditor role.');
        $support->assignRole($roles['ROLE_AUDITOR']);
        $manager->persist($support);
        $manager->flush();

        $manager->persist(new GroupMembership($editor->id(), $support, $now));

        // --- object-level grants: the reason this ACL exists -------------------------
        // A grant on ONE user record, to a user who has no class-level access to users at all.
        $manager->persist(new AclEntry(
            AclSubjectType::User,
            $viewer->id(),
            User::class,
            $editor->id(),
            $permissions[PermissionCatalog::USER_READ],
            AclEffect::Allow,
            $now,
            null,
            $admin->id(),
        ));

        // An explicit deny carving an exception out of the group's broader allow. This is the
        // case a role-only model cannot express without inventing a role per exception.
        $manager->persist(new AclEntry(
            AclSubjectType::User,
            $editor->id(),
            User::class,
            $admin->id(),
            $permissions[PermissionCatalog::USER_READ],
            AclEffect::Deny,
            $now,
            null,
            $admin->id(),
        ));

        $manager->flush();
    }

    /**
     * @return array<string, Permission>
     */
    private function permissions(ObjectManager $manager, DateTimeImmutable $now): array
    {
        $permissions = [];

        foreach (PermissionCatalog::all() as $name) {
            $existing = $manager->getRepository(Permission::class)->findOneBy(['name' => $name]);
            $permission = $existing instanceof Permission ? $existing : new Permission($name, $now);

            $manager->persist($permission);
            $permissions[$name] = $permission;
        }

        $manager->flush();

        return $permissions;
    }

    /**
     * @param array<string, Permission> $permissions
     *
     * @return array<string, Role>
     */
    private function roles(ObjectManager $manager, DateTimeImmutable $now, array $permissions): array
    {
        $definitions = [
            Role::SUPER_ADMIN => [],
            Role::DEFAULT_USER => [
                PermissionCatalog::ACCOUNT_READ,
                PermissionCatalog::ACCOUNT_UPDATE,
                PermissionCatalog::ACCOUNT_DELETE,
                PermissionCatalog::ACCOUNT_EXPORT,
            ],
            'ROLE_AUDITOR' => [
                PermissionCatalog::AUDIT_READ,
                PermissionCatalog::USER_READ,
            ],
        ];

        $roles = [];

        foreach ($definitions as $name => $grants) {
            $existing = $manager->getRepository(Role::class)->findOneBy(['name' => $name]);
            $role = $existing instanceof Role ? $existing : new Role($name, $now);

            foreach ($grants as $permissionName) {
                $role->grant($permissions[$permissionName]);
            }

            $manager->persist($role);
            $roles[$name] = $role;
        }

        $manager->flush();

        return $roles;
    }

    private function user(
        ObjectManager $manager,
        PasswordHasherInterface $hasher,
        string $email,
        DateTimeImmutable $now,
    ): User {
        $user = new User($email, $hasher->hash(self::DEMO_PASSWORD), $now);
        $user->verifyEmail($now);

        $manager->persist($user);

        return $user;
    }
}
