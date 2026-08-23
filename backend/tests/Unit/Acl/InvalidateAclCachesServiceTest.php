<?php

declare(strict_types=1);

namespace App\Tests\Unit\Acl;

use App\Acl\Application\AclCache;
use App\Acl\Application\Service\InvalidateAclCachesService;
use App\Acl\Domain\Role;
use App\Acl\Domain\UserGroup;
use App\Tests\Unit\Acl\Double\StubAclVersionProvider;
use App\Tests\Unit\Acl\Double\StubSubjectRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Uid\Uuid;

/**
 * The fan-out tests.
 *
 * Each case asserts that a change reaches everyone whose cached decisions it invalidates. The
 * failure these guard against is silent — a grant that succeeds but does not take effect — so
 * "the version went up" is the only observable worth asserting.
 */
final class InvalidateAclCachesServiceTest extends TestCase
{
    private StubAclVersionProvider $versions;
    private StubSubjectRepository $subjects;
    private InvalidateAclCachesService $service;

    protected function setUp(): void
    {
        $this->versions = new StubAclVersionProvider();
        $this->subjects = new StubSubjectRepository();
        $this->service = new InvalidateAclCachesService(
            $this->versions,
            $this->subjects,
            new AclCache(new ArrayAdapter(), $this->versions),
        );
    }

    public function testItBumpsEveryUserItIsGiven(): void
    {
        $alice = Uuid::v7();
        $bob = Uuid::v7();

        $this->service->forUsers([$alice, $bob]);

        self::assertSame(2, $this->versions->versionFor($alice));
        self::assertSame(2, $this->versions->versionFor($bob));
    }

    public function testItDoesNothingForAnEmptyList(): void
    {
        $nobody = Uuid::v7();

        $this->service->forUsers([]);

        self::assertSame(1, $this->versions->versionFor($nobody));
    }

    public function testAGroupChangeReachesEveryMember(): void
    {
        $group = new UserGroup('support', new DateTimeImmutable());
        $member = Uuid::v7();
        $stranger = Uuid::v7();
        $this->subjects->defineGroupMembers($group, [$member]);

        $this->service->forGroup($group);

        self::assertSame(2, $this->versions->versionFor($member));
        self::assertSame(1, $this->versions->versionFor($stranger), 'a non-member must not be invalidated');
    }

    /**
     * The case that makes this service worth having: a role attached to a group is held by
     * people who were never granted it individually, and they are the ones a naive
     * "bump the direct holders" implementation would miss.
     */
    public function testARoleChangeReachesHoldersReachedThroughAGroup(): void
    {
        $role = new Role('ROLE_AUDITOR', new DateTimeImmutable());
        $directHolder = Uuid::v7();
        $throughGroup = Uuid::v7();
        $this->subjects->defineRoleHolders($role, [$directHolder, $throughGroup]);

        $this->service->forRole($role);

        self::assertSame(2, $this->versions->versionFor($directHolder));
        self::assertSame(2, $this->versions->versionFor($throughGroup));
    }
}
