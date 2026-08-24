<?php

declare(strict_types=1);

namespace App\Tests\Integration\Acl;

use App\Account\Domain\User;
use App\Acl\Application\PermissionResolver;
use App\Acl\Domain\AclEffect;
use App\Acl\Domain\AclEntry;
use App\Acl\Domain\AclSubjectType;
use App\Acl\Domain\Permission;
use App\Acl\Domain\PermissionCatalog;
use App\Acl\Domain\Role;
use App\Acl\Domain\UserRole;
use App\Acl\Infrastructure\AclCriteriaBuilder;
use App\Shared\Domain\Clock;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The list and the item check must never disagree.
 *
 * This is the test the whole per-object ACL design hangs on. There are two implementations of
 * the same rules — PermissionResolver decides one object at a time in PHP,
 * AclCriteriaBuilder decides all of them at once in SQL — and any divergence is a security
 * bug in one direction (a list showing what a direct fetch would refuse) or a support ticket
 * in the other (a list hiding what the user may actually open).
 *
 * Two implementations of one rule set will drift. The only defence is a test that runs both
 * against the same data and compares, on every combination that matters.
 *
 * @see AclCriteriaBuilder for why the obvious SQL is wrong
 */
#[CoversClass(AclCriteriaBuilder::class)]
#[CoversClass(PermissionResolver::class)]
final class AclConsistencyTest extends KernelTestCase
{
    private const string PERMISSION = PermissionCatalog::USER_READ;

    private EntityManagerInterface $em;
    private PermissionResolver $resolver;
    private AclCriteriaBuilder $criteria;
    private Clock $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->resolver = $container->get(PermissionResolver::class);
        $this->criteria = $container->get(AclCriteriaBuilder::class);
        $this->clock = $container->get(Clock::class);
    }

    public function testNoGrantsMeansAnEmptyListAndUniformDenial(): void
    {
        $caller = $this->user('caller');
        $records = [$this->user('a'), $this->user('b')];
        $this->em->flush();

        $this->assertConsistent($caller, $records, $this->resolver, $this->criteria, $this->em);
    }

    public function testAnObjectLevelGrantAppearsInTheListAndNothingElseDoes(): void
    {
        $caller = $this->user('caller');
        $visible = $this->user('visible');
        $hidden = $this->user('hidden');
        $this->em->flush();

        $this->grant($caller->id(), $visible->id(), AclEffect::Allow);
        $this->em->flush();

        $allowed = $this->filtered($caller);

        self::assertContains($visible->id()->toRfc4122(), $allowed);
        self::assertNotContains($hidden->id()->toRfc4122(), $allowed);
        $this->assertConsistent($caller, [$visible, $hidden], $this->resolver, $this->criteria, $this->em);
    }

    public function testAClassLevelGrantExposesEveryRecord(): void
    {
        $caller = $this->user('caller');
        $records = [$this->user('a'), $this->user('b'), $this->user('c')];
        $this->em->flush();

        $this->grant($caller->id(), null, AclEffect::Allow);
        $this->em->flush();

        $allowed = $this->filtered($caller);

        // Asserted by membership, not by an absolute count. A count couples this test to how
        // many User rows happen to exist, which makes it fail for reasons that have nothing to
        // do with the ACL — and the failure message tells you nothing about which.
        foreach ([...$records, $caller] as $record) {
            self::assertContains(
                $record->id()->toRfc4122(),
                $allowed,
                'A class-level grant covers every instance, including the caller\'s own row.',
            );
        }

        $this->assertConsistent($caller, $records, $this->resolver, $this->criteria, $this->em);
    }

    /**
     * The case the obvious SQL gets wrong.
     *
     * "EXISTS an allow AND NOT EXISTS a deny" makes deny globally dominant, so this row would
     * be filtered out of the list while a direct permission check still granted it.
     */
    public function testAnObjectLevelAllowSurvivesAClassLevelDeny(): void
    {
        $caller = $this->user('caller');
        $exception = $this->user('exception');
        $ordinary = $this->user('ordinary');
        $this->em->flush();

        $this->grant($caller->id(), null, AclEffect::Deny);
        $this->grant($caller->id(), $exception->id(), AclEffect::Allow);
        $this->em->flush();

        $allowed = $this->filtered($caller);

        self::assertContains(
            $exception->id()->toRfc4122(),
            $allowed,
            'A specific grant must override a general refusal, in SQL exactly as it does in the resolver.',
        );
        self::assertNotContains($ordinary->id()->toRfc4122(), $allowed);
        $this->assertConsistent($caller, [$exception, $ordinary], $this->resolver, $this->criteria, $this->em);
    }

    public function testAnObjectLevelDenyOverridesAClassLevelAllow(): void
    {
        $caller = $this->user('caller');
        $forbidden = $this->user('forbidden');
        $ordinary = $this->user('ordinary');
        $this->em->flush();

        $this->grant($caller->id(), null, AclEffect::Allow);
        $this->grant($caller->id(), $forbidden->id(), AclEffect::Deny);
        $this->em->flush();

        $allowed = $this->filtered($caller);

        self::assertNotContains($forbidden->id()->toRfc4122(), $allowed);
        self::assertContains($ordinary->id()->toRfc4122(), $allowed);
        $this->assertConsistent($caller, [$forbidden, $ordinary], $this->resolver, $this->criteria, $this->em);
    }

    public function testTheRoleFallbackExposesEveryRecordThroughBothPaths(): void
    {
        $caller = $this->user('caller');
        $records = [$this->user('a'), $this->user('b')];
        $this->em->flush();

        $role = new Role('ROLE_READER_'.bin2hex(random_bytes(4)), $this->clock->now());
        $role->grant($this->permission());
        $this->em->persist($role);
        $this->em->persist(new UserRole($caller->id(), $role, $this->clock->now()));
        $this->em->flush();

        $this->assertConsistent($caller, $records, $this->resolver, $this->criteria, $this->em);
        self::assertNotEmpty($this->filtered($caller), 'A role grant must also widen the list.');
    }

    public function testAnObjectLevelDenyBeatsTheRoleFallback(): void
    {
        $caller = $this->user('caller');
        $forbidden = $this->user('forbidden');
        $this->em->flush();

        $role = new Role('ROLE_READER_'.bin2hex(random_bytes(4)), $this->clock->now());
        $role->grant($this->permission());
        $this->em->persist($role);
        $this->em->persist(new UserRole($caller->id(), $role, $this->clock->now()));
        $this->grant($caller->id(), $forbidden->id(), AclEffect::Deny);
        $this->em->flush();

        self::assertNotContains($forbidden->id()->toRfc4122(), $this->filtered($caller));
        $this->assertConsistent($caller, [$forbidden], $this->resolver, $this->criteria, $this->em);
    }

    public function testAnExpiredGrantIsInvisibleToBothPaths(): void
    {
        $caller = $this->user('caller');
        $stale = $this->user('stale');
        $this->em->flush();

        $this->grant($caller->id(), $stale->id(), AclEffect::Allow, $this->clock->now()->modify('-1 hour'));
        $this->em->flush();

        self::assertNotContains($stale->id()->toRfc4122(), $this->filtered($caller));
        $this->assertConsistent($caller, [$stale], $this->resolver, $this->criteria, $this->em);
    }

    /**
     * The assertion that matters: ask both implementations about the same records.
     *
     * @param list<User> $records
     */
    private function assertConsistent(
        User $caller,
        array $records,
        PermissionResolver $resolver,
        AclCriteriaBuilder $criteria,
        EntityManagerInterface $em,
    ): void {
        $qb = $em->createQueryBuilder()->select('u')->from(User::class, 'u');
        $criteria->apply($qb, 'u', self::PERMISSION, $caller->id());

        /** @var list<User> $listed */
        $listed = $qb->getQuery()->getResult();
        $listedIds = array_map(static fn (User $u): string => $u->id()->toRfc4122(), $listed);

        foreach ($records as $record) {
            $resolverSays = $resolver->isGranted($caller->id(), self::PERMISSION, $record);
            $listSays = \in_array($record->id()->toRfc4122(), $listedIds, true);

            self::assertSame($resolverSays, $listSays, \sprintf(
                "The resolver and the collection filter disagree about %s.\n"
                ."  PermissionResolver: %s\n  AclCriteriaBuilder: %s\n"
                .'One of them is wrong, and if it is the filter, a list is showing or hiding '
                .'rows a direct fetch would not.',
                $record->username(),
                $resolverSays ? 'granted' : 'denied',
                $listSays ? 'included' : 'excluded',
            ));
        }
    }

    /** @return list<string> */
    private function filtered(User $caller): array
    {
        $qb = $this->em->createQueryBuilder()->select('u')->from(User::class, 'u');
        $this->criteria->apply($qb, 'u', self::PERMISSION, $caller->id());

        /** @var list<User> $result */
        $result = $qb->getQuery()->getResult();

        return array_map(static fn (User $u): string => $u->id()->toRfc4122(), $result);
    }

    private function user(string $label): User
    {
        $user = new User(
            \sprintf('%s-%s@acl.test', $label, bin2hex(random_bytes(6))),
            'x',
            $this->clock->now(),
        );
        $this->em->persist($user);

        return $user;
    }

    private function grant(
        Uuid $subjectId,
        ?Uuid $resourceId,
        AclEffect $effect,
        ?DateTimeImmutable $expiresAt = null,
    ): void {
        $this->em->persist(new AclEntry(
            AclSubjectType::User,
            $subjectId,
            User::class,
            $resourceId,
            $this->permission(),
            $effect,
            $this->clock->now(),
            $expiresAt,
        ));
    }

    private function permission(): Permission
    {
        $permission = $this->em->getRepository(Permission::class)->findOneBy(['name' => self::PERMISSION]);

        if (!$permission instanceof Permission) {
            $permission = new Permission(self::PERMISSION, $this->clock->now());
            $this->em->persist($permission);
            $this->em->flush();
        }

        return $permission;
    }
}
