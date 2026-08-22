<?php

declare(strict_types=1);

namespace App\Tests\Unit\Acl;

use App\Acl\Application\AclCache;
use App\Acl\Application\PermissionResolver;
use App\Acl\Domain\AclEffect;
use App\Acl\Domain\AclEntry;
use App\Acl\Domain\AclSubjectType;
use App\Acl\Domain\AclTier;
use App\Acl\Domain\Permission;
use App\Acl\Domain\Role;
use App\Acl\Domain\SubjectSet;
use App\Tests\Unit\Acl\Double\FixedClock;
use App\Tests\Unit\Acl\Double\Folder;
use App\Tests\Unit\Acl\Double\InMemoryAclEntryRepository;
use App\Tests\Unit\Acl\Double\Note;
use App\Tests\Unit\Acl\Double\StubAclVersionProvider;
use App\Tests\Unit\Acl\Double\StubRoleRepository;
use App\Tests\Unit\Acl\Double\StubSubjectRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Uid\Uuid;

/**
 * The ACL decision matrix.
 *
 * This is the most important test in the repository. The resolver's algorithm is short, and
 * every single line of it is a security decision — so each tier, each precedence rule and
 * each fallthrough gets its own named case. A failure here should read as a sentence about
 * authorization, not as an assertion about an array.
 *
 * Deliberately a *unit* test with in-memory doubles: it has to be cheap enough that nobody
 * hesitates to add the next case.
 */
#[CoversClass(PermissionResolver::class)]
final class PermissionResolverTest extends TestCase
{
    private const string PERMISSION = 'note.read';

    private InMemoryAclEntryRepository $entries;
    private StubRoleRepository $roles;
    private StubSubjectRepository $subjects;
    private FixedClock $clock;
    private PermissionResolver $resolver;
    private Uuid $userId;
    private Permission $permission;

    protected function setUp(): void
    {
        $this->entries = new InMemoryAclEntryRepository();
        $this->roles = new StubRoleRepository();
        $this->subjects = new StubSubjectRepository();
        $this->clock = new FixedClock();
        $this->userId = Uuid::v7();
        $this->permission = new Permission(self::PERMISSION, $this->clock->now());

        $this->subjects->define(new SubjectSet($this->userId));

        $this->resolver = new PermissionResolver(
            $this->entries,
            $this->roles,
            $this->subjects,
            $this->clock,
            new AclCache(new ArrayAdapter(), new StubAclVersionProvider()),
        );
    }

    // ---------------------------------------------------------------- default

    public function testDeniesWhenNothingGrantsAnything(): void
    {
        self::assertFalse($this->resolver->isGranted($this->userId, self::PERMISSION, new Note()));
    }

    public function testDefaultDenialExplainsItself(): void
    {
        $decision = $this->resolver->explain($this->userId, self::PERMISSION, new Note());

        self::assertFalse($decision->granted);
        self::assertSame(AclTier::Default, $decision->tier);
    }

    // ---------------------------------------------------------------- tier 1: the object

    public function testObjectLevelAllowGrants(): void
    {
        $note = new Note();
        $this->grant(AclSubjectType::User, $this->userId, $note->id());

        self::assertTrue($this->resolver->isGranted($this->userId, self::PERMISSION, $note));
    }

    public function testObjectLevelDenyRefuses(): void
    {
        $note = new Note();
        $this->grant(AclSubjectType::User, $this->userId, $note->id(), AclEffect::Deny);

        self::assertFalse($this->resolver->isGranted($this->userId, self::PERMISSION, $note));
    }

    public function testDenyBeatsAllowWithinTheSameTier(): void
    {
        $note = new Note();
        $this->grant(AclSubjectType::User, $this->userId, $note->id(), AclEffect::Allow);
        $this->grant(AclSubjectType::Group, $groupId = Uuid::v7(), $note->id(), AclEffect::Deny);
        $this->subjects->define(new SubjectSet($this->userId, groupIds: [$groupId]));

        self::assertFalse(
            $this->resolver->isGranted($this->userId, self::PERMISSION, $note),
            'Within one tier a deny must win regardless of which entry was created first — '
            .'otherwise the outcome depends on row order.',
        );
    }

    public function testAnObjectLevelGrantOverridesAClassLevelDeny(): void
    {
        $note = new Note();
        $this->grant(AclSubjectType::User, $this->userId, null, AclEffect::Deny);
        $this->grant(AclSubjectType::User, $this->userId, $note->id(), AclEffect::Allow);

        self::assertTrue(
            $this->resolver->isGranted($this->userId, self::PERMISSION, $note),
            'The more specific tier decides. This is what makes "share this one document with '
            .'someone otherwise denied" expressible at all.',
        );
    }

    // ---------------------------------------------------------------- tier 2: inheritance

    public function testAGrantOnTheParentCoversTheChild(): void
    {
        $folder = new Folder();
        $note = new Note($folder);
        $this->grant(AclSubjectType::User, $this->userId, $folder->id());

        self::assertTrue($this->resolver->isGranted($this->userId, self::PERMISSION, $note));
    }

    public function testInheritedGrantIsReportedAsInherited(): void
    {
        $folder = new Folder();
        $note = new Note($folder);
        $this->grant(AclSubjectType::User, $this->userId, $folder->id());

        self::assertSame(AclTier::Inherited, $this->resolver->explain($this->userId, self::PERMISSION, $note)->tier);
    }

    public function testADenyOnTheChildBeatsAnAllowOnTheParent(): void
    {
        $folder = new Folder();
        $note = new Note($folder);
        $this->grant(AclSubjectType::User, $this->userId, $folder->id(), AclEffect::Allow);
        $this->grant(AclSubjectType::User, $this->userId, $note->id(), AclEffect::Deny);

        self::assertFalse($this->resolver->isGranted($this->userId, self::PERMISSION, $note));
    }

    public function testInheritanceWalksMoreThanOneLevel(): void
    {
        $grandparent = new Folder();
        $parent = new Folder($grandparent);
        $note = new Note($parent);
        $this->grant(AclSubjectType::User, $this->userId, $grandparent->id());

        self::assertTrue($this->resolver->isGranted($this->userId, self::PERMISSION, $note));
    }

    public function testTheNearerAncestorWins(): void
    {
        $grandparent = new Folder();
        $parent = new Folder($grandparent);
        $note = new Note($parent);
        $this->grant(AclSubjectType::User, $this->userId, $grandparent->id(), AclEffect::Allow);
        $this->grant(AclSubjectType::User, $this->userId, $parent->id(), AclEffect::Deny);

        self::assertFalse($this->resolver->isGranted($this->userId, self::PERMISSION, $note));
    }

    // ---------------------------------------------------------------- tier 3: class level

    public function testClassLevelAllowCoversEveryInstance(): void
    {
        $this->grant(AclSubjectType::User, $this->userId, null);

        self::assertTrue($this->resolver->isGranted($this->userId, self::PERMISSION, new Note()));
        self::assertTrue($this->resolver->isGranted($this->userId, self::PERMISSION, new Note()));
    }

    // ---------------------------------------------------------------- subjects

    public function testAGrantToAGroupAppliesToItsMembers(): void
    {
        $note = new Note();
        $groupId = Uuid::v7();
        $this->subjects->define(new SubjectSet($this->userId, groupIds: [$groupId]));
        $this->grant(AclSubjectType::Group, $groupId, $note->id());

        self::assertTrue($this->resolver->isGranted($this->userId, self::PERMISSION, $note));
    }

    public function testAGrantToARoleAppliesToItsHolders(): void
    {
        $note = new Note();
        $roleId = Uuid::v7();
        $this->subjects->define(new SubjectSet($this->userId, roleIds: [$roleId]));
        $this->grant(AclSubjectType::Role, $roleId, $note->id());

        self::assertTrue($this->resolver->isGranted($this->userId, self::PERMISSION, $note));
    }

    public function testAGrantToSomebodyElseDoesNotApply(): void
    {
        $note = new Note();
        $this->grant(AclSubjectType::User, Uuid::v7(), $note->id());

        self::assertFalse($this->resolver->isGranted($this->userId, self::PERMISSION, $note));
    }

    // ---------------------------------------------------------------- expiry

    public function testAnExpiredEntryIsIgnored(): void
    {
        $note = new Note();
        $this->grant(
            AclSubjectType::User,
            $this->userId,
            $note->id(),
            expiresAt: new DateTimeImmutable('2026-01-01T11:59:59+00:00'),
        );

        self::assertFalse($this->resolver->isGranted($this->userId, self::PERMISSION, $note));
    }

    public function testAnUnexpiredEntryStillApplies(): void
    {
        $note = new Note();
        $this->grant(
            AclSubjectType::User,
            $this->userId,
            $note->id(),
            expiresAt: new DateTimeImmutable('2026-06-01T00:00:00+00:00'),
        );

        self::assertTrue($this->resolver->isGranted($this->userId, self::PERMISSION, $note));
    }

    public function testAnExpiredDenyStopsBlocking(): void
    {
        $note = new Note();
        $this->grant(AclSubjectType::User, $this->userId, null, AclEffect::Allow);
        $this->grant(
            AclSubjectType::User,
            $this->userId,
            $note->id(),
            AclEffect::Deny,
            new DateTimeImmutable('2026-01-01T11:00:00+00:00'),
        );

        self::assertTrue(
            $this->resolver->isGranted($this->userId, self::PERMISSION, $note),
            'An expired deny must stop applying, or time-boxed suspensions would be permanent.',
        );
    }

    // ---------------------------------------------------------------- tier 4: RBAC

    public function testRoleBasedFallbackGrantsWhenNoEntryApplies(): void
    {
        $role = new Role('ROLE_EDITOR', $this->clock->now());
        $this->roles->withRole($role, self::PERMISSION);
        $this->subjects->define(new SubjectSet($this->userId, roleIds: [$role->id()], roleNames: [$role->name()]));

        self::assertTrue($this->resolver->isGranted($this->userId, self::PERMISSION, new Note()));
        self::assertSame(AclTier::Rbac, $this->resolver->explain($this->userId, self::PERMISSION, new Note())->tier);
    }

    public function testAnEntryTierBeatsTheRoleFallback(): void
    {
        $note = new Note();
        $role = new Role('ROLE_EDITOR', $this->clock->now());
        $this->roles->withRole($role, self::PERMISSION);
        $this->subjects->define(new SubjectSet($this->userId, roleIds: [$role->id()], roleNames: [$role->name()]));
        $this->grant(AclSubjectType::User, $this->userId, $note->id(), AclEffect::Deny);

        self::assertFalse(
            $this->resolver->isGranted($this->userId, self::PERMISSION, $note),
            'A specific deny must be able to carve an exception out of a broad role grant.',
        );
    }

    public function testRoleFallbackAppliesToClassLevelChecksToo(): void
    {
        $role = new Role('ROLE_EDITOR', $this->clock->now());
        $this->roles->withRole($role, self::PERMISSION);
        $this->subjects->define(new SubjectSet($this->userId, roleIds: [$role->id()], roleNames: [$role->name()]));

        self::assertTrue($this->resolver->isGranted($this->userId, self::PERMISSION));
    }

    // ---------------------------------------------------------------- super admin

    public function testSuperAdminBypassesEverythingIncludingAnExplicitDeny(): void
    {
        $note = new Note();
        $this->subjects->define(new SubjectSet($this->userId, roleNames: [Role::SUPER_ADMIN]));
        $this->grant(AclSubjectType::User, $this->userId, $note->id(), AclEffect::Deny);

        self::assertTrue($this->resolver->isGranted($this->userId, self::PERMISSION, $note));
        self::assertSame(AclTier::SuperAdmin, $this->resolver->explain($this->userId, self::PERMISSION, $note)->tier);
    }

    // ---------------------------------------------------------------- other permissions

    public function testAGrantForOnePermissionDoesNotLeakToAnother(): void
    {
        $note = new Note();
        $this->grant(AclSubjectType::User, $this->userId, $note->id());

        self::assertFalse($this->resolver->isGranted($this->userId, 'note.delete', $note));
    }

    private function grant(
        AclSubjectType $type,
        Uuid $subjectId,
        ?Uuid $resourceId,
        AclEffect $effect = AclEffect::Allow,
        ?DateTimeImmutable $expiresAt = null,
    ): void {
        $this->entries->add(new AclEntry(
            $type,
            $subjectId,
            Note::class,
            $resourceId,
            $this->permission,
            $effect,
            $this->clock->now(),
            $expiresAt,
        ));
    }
}
