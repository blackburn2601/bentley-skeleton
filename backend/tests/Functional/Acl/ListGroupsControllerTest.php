<?php

declare(strict_types=1);

namespace App\Tests\Functional\Acl;

use App\Account\Domain\User;
use App\Acl\Domain\GroupMembership;
use App\Acl\Domain\PermissionCatalog;
use App\Acl\Domain\Role;
use App\Acl\Domain\UserGroup;
use App\Tests\Functional\ApiTestCase;

/**
 * GET /api/v1/admin/groups.
 */
final class ListGroupsControllerTest extends ApiTestCase
{
    private const string ROLE = 'ROLE_TEST_GROUP_READER';

    public function testItRejectsAnAnonymousCaller(): void
    {
        $this->json('GET', '/api/v1/admin/groups');

        self::assertResponseStatusCodeSame(401);
    }

    public function testItRejectsACallerWithoutThePermission(): void
    {
        $this->logIn($this->createUser('nobody'));

        $this->json('GET', '/api/v1/admin/groups');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * This test used to assert only that `total` matched `count(items)`, which both being zero
     * satisfies. The test database has no groups, so it passed without ever running the code —
     * and the real endpoint then failed with a 500 the moment it met a group with a member.
     * A list test that does not create a row is not a test of a list.
     */
    public function testItReturnsAGroupWithItsRolesAndMemberCount(): void
    {
        $member = $this->createUser('member');
        $this->groupWithMember('platform-team', 'ROLE_TEST_GROUP_ROLE', $member);

        $this->logIn($this->permittedCaller());
        $this->json('GET', '/api/v1/admin/groups');

        self::assertResponseIsSuccessful();
        $body = $this->pageJson();

        // Every collection returns the same four keys, paged or not (ADR-0019).
        self::assertCount($body['total'], $body['items']);
        self::assertGreaterThanOrEqual(1, $body['page']);

        $group = null;
        foreach ($body['items'] as $row) {
            if ('platform-team' === $row['name']) {
                $group = $row;
            }
        }

        self::assertNotNull($group, 'the created group must appear in the list');
        self::assertSame(['id', 'name', 'description', 'roles', 'memberCount'], array_keys($group));
        self::assertSame(['ROLE_TEST_GROUP_ROLE'], $group['roles']);
        self::assertSame(1, $group['memberCount'], 'member counts come from GroupMembership rows');
    }

    /** A group carrying one role, with one member — the shape memberIdsOf() has to survive. */
    private function groupWithMember(string $name, string $roleName, User $member): void
    {
        $role = $this->em->getRepository(Role::class)->findOneBy(['name' => $roleName]);

        if (!$role instanceof Role) {
            $role = new Role($roleName, $this->clock->now());
            $this->em->persist($role);
        }

        $group = new UserGroup($name, $this->clock->now());
        $group->assignRole($role);
        $this->em->persist($group);
        $this->em->flush();

        $this->em->persist(new GroupMembership($member->id(), $group, $this->clock->now()));
        $this->em->flush();
    }

    private function permittedCaller(): User
    {
        $caller = $this->createUser('group-reader');
        $this->assignRole($caller, self::ROLE);
        $this->grantRolePermission(self::ROLE, PermissionCatalog::GROUP_READ);

        return $caller;
    }
}
