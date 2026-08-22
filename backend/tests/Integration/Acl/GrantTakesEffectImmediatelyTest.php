<?php

declare(strict_types=1);

namespace App\Tests\Integration\Acl;

use App\Account\Domain\User;
use App\Acl\Application\AclFacade;
use App\Acl\Domain\AclEffect;
use App\Acl\Domain\AclEntry;
use App\Acl\Domain\AclSubjectType;
use App\Acl\Domain\Permission;
use App\Acl\Domain\PermissionCatalog;
use App\Audit\Domain\SecurityEvent;
use App\Shared\Application\AclVersionProvider;
use App\Shared\Domain\Clock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A grant takes effect on the next request, with no re-login (ADR-0011).
 *
 * The property the whole "no permissions in the token" decision buys. It is also the one most
 * easily lost by accident: any change that starts serving permissions from a cache keyed on
 * something other than `acl_version`, or that reports the advisory list from roles alone,
 * breaks it — and breaks it silently, because everything still *works*, just with the old
 * answer.
 *
 * This exists because it did break. The advisory list asked the resolver with a null resource,
 * which answers only the role question, so a class-level grant never appeared in `/me` and the
 * UI hid controls the server would have allowed.
 */
final class GrantTakesEffectImmediatelyTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AclFacade $acl;
    private AclVersionProvider $versions;
    private Clock $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->acl = $container->get(AclFacade::class);
        $this->versions = $container->get(AclVersionProvider::class);
        $this->clock = $container->get(Clock::class);
    }

    public function testAClassLevelGrantAppearsWithoutReAuthenticating(): void
    {
        $user = $this->user();

        self::assertNotContains(
            PermissionCatalog::AUDIT_READ,
            $this->acl->classLevelPermissionsOf($user->id()),
            'Precondition: the user should not hold audit.read yet.',
        );

        $this->grantClassLevel($user, SecurityEvent::class, PermissionCatalog::AUDIT_READ);

        self::assertContains(
            PermissionCatalog::AUDIT_READ,
            $this->acl->classLevelPermissionsOf($user->id()),
            'A class-level grant must appear immediately. If this fails, the permission list is '
            .'being answered from roles alone, and the UI will hide controls the server allows.',
        );
    }

    public function testTheDecisionItselfChangesImmediately(): void
    {
        $user = $this->user();
        $event = new SecurityEvent(\App\Shared\Domain\SecurityEventType::LoginSucceeded, $this->clock->now());

        self::assertFalse($this->acl->isGranted($user->id(), PermissionCatalog::AUDIT_READ, $event));

        $this->grantClassLevel($user, SecurityEvent::class, PermissionCatalog::AUDIT_READ);

        self::assertTrue(
            $this->acl->isGranted($user->id(), PermissionCatalog::AUDIT_READ, $event),
            'The cache is keyed on acl_version; bumping it must make the new grant visible at '
            .'once, with no invalidation sweep and no re-login (ADR-0011).',
        );
    }

    public function testRevokingIsAlsoImmediate(): void
    {
        $user = $this->user();
        $entry = $this->grantClassLevel($user, SecurityEvent::class, PermissionCatalog::AUDIT_READ);

        self::assertContains(PermissionCatalog::AUDIT_READ, $this->acl->classLevelPermissionsOf($user->id()));

        $this->em->remove($entry);
        $this->em->flush();
        $this->versions->bumpAll([$user->id()]);

        self::assertNotContains(
            PermissionCatalog::AUDIT_READ,
            $this->acl->classLevelPermissionsOf($user->id()),
            'Revocation must be as immediate as granting — that is the entire reason the token '
            .'carries no permission list.',
        );
    }

    /**
     * @param class-string $resourceClass
     */
    private function grantClassLevel(User $user, string $resourceClass, string $permissionName): AclEntry
    {
        $entry = new AclEntry(
            AclSubjectType::User,
            $user->id(),
            $resourceClass,
            null,
            $this->permission($permissionName),
            AclEffect::Allow,
            $this->clock->now(),
        );

        $this->em->persist($entry);
        $this->em->flush();

        // Every grant change bumps the version. Forgetting this is exactly how a revoked
        // permission keeps working until the cache expires.
        $this->versions->bumpAll([$user->id()]);

        return $entry;
    }

    private function user(): User
    {
        $user = new User('grant-'.bin2hex(random_bytes(5)).'@acl.test', 'x', $this->clock->now());
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function permission(string $name): Permission
    {
        $permission = $this->em->getRepository(Permission::class)->findOneBy(['name' => $name]);

        if (!$permission instanceof Permission) {
            $permission = new Permission($name, $this->clock->now());
            $this->em->persist($permission);
            $this->em->flush();
        }

        return $permission;
    }
}
