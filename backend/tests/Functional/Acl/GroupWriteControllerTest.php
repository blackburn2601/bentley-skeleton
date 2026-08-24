<?php

declare(strict_types=1);

namespace App\Tests\Functional\Acl;

use App\Account\Domain\User;
use App\Acl\Domain\PermissionCatalog;
use App\Acl\Domain\UserGroup;
use App\Tests\Functional\ApiTestCase;

/**
 * The group write endpoints: create, update, set-roles, set-members, delete.
 */
final class GroupWriteControllerTest extends ApiTestCase
{
    private const string ROLE = 'ROLE_TEST_GROUP_ADMIN';
    private const string CARRIED = 'ROLE_TEST_CARRIED';

    public function testAnonymousCallersAreRefused(): void
    {
        $this->json('POST', '/api/v1/admin/groups', ['name' => 'nope']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testACallerWithoutThePermissionIsRefused(): void
    {
        $this->logIn($this->createUser('nobody'));

        $this->json('POST', '/api/v1/admin/groups', ['name' => 'nope'], $this->csrfHeader());

        self::assertResponseStatusCodeSame(403);
    }

    public function testItCreatesRenamesAndDeletesAGroup(): void
    {
        $this->logIn($this->groupAdmin());

        $id = $this->createGroup('first-name');

        $this->json('PATCH', "/api/v1/admin/groups/{$id}", [
            'name' => 'second-name',
            'description' => 'Renamed by a test',
        ], $this->csrfHeader());
        self::assertResponseIsSuccessful();
        self::assertSame('second-name', $this->responseJson()['name']);

        $this->json('DELETE', "/api/v1/admin/groups/{$id}", [], $this->csrfHeader());
        self::assertResponseIsSuccessful();
        self::assertNull($this->em->getRepository(UserGroup::class)->findOneBy(['name' => 'second-name']));
    }

    public function testItRefusesADuplicateName(): void
    {
        $this->logIn($this->groupAdmin());
        $this->createGroup('taken-name');

        $this->json('POST', '/api/v1/admin/groups', ['name' => 'taken-name'], $this->csrfHeader());

        self::assertResponseStatusCodeSame(409);
    }

    public function testItReplacesTheRoleSet(): void
    {
        $this->logIn($this->groupAdmin());
        $id = $this->createGroup('role-carrier');

        $this->json('PUT', "/api/v1/admin/groups/{$id}/roles", ['roles' => [self::CARRIED]], $this->csrfHeader());

        self::assertResponseIsSuccessful();
        self::assertSame([self::CARRIED], $this->responseJson()['roles']);
    }

    /**
     * Group membership is a grant route: everyone in the group inherits its roles. So the same
     * ceiling applies as for role permissions — otherwise `group.update` promotes anyone.
     */
    public function testItRefusesToAttachARoleCarryingAPermissionTheCallerDoesNotHold(): void
    {
        // groupAdmin() first: it creates CARRIED, and a permission cannot attach to a role
        // that does not exist yet.
        $admin = $this->groupAdmin();
        $this->grantRolePermission(self::CARRIED, PermissionCatalog::USER_DELETE);
        $this->logIn($admin);
        $id = $this->createGroup('escalation-attempt');

        $this->json('PUT', "/api/v1/admin/groups/{$id}/roles", ['roles' => [self::CARRIED]], $this->csrfHeader());

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * A member added through a group must gain its access on the next request, and a member
     * removed must lose it — the second half is the one that fails silently if the cache of
     * the person who LEFT is not invalidated.
     */
    public function testMembershipGrantsAndThenRemovesInheritedAccess(): void
    {
        $admin = $this->groupAdmin();
        $this->grantRolePermission(self::CARRIED, PermissionCatalog::ROLE_READ);
        $member = $this->createUser('member');

        $this->logIn($admin);
        $id = $this->createGroup('access-granting');
        $this->json('PUT', "/api/v1/admin/groups/{$id}/roles", ['roles' => [self::CARRIED]], $this->csrfHeader());
        self::assertResponseIsSuccessful();

        $this->json('PUT', "/api/v1/admin/groups/{$id}/members", [
            'members' => [$member->id()->toRfc4122()],
        ], $this->csrfHeader());
        self::assertResponseIsSuccessful();

        $this->logOut();
        $this->logIn($member);
        $this->json('GET', '/api/v1/admin/roles');
        self::assertResponseIsSuccessful('membership must grant the group\'s roles');

        // Now remove them, by sending an empty list.
        $this->logOut();
        $this->logIn($admin);
        $this->json('PUT', "/api/v1/admin/groups/{$id}/members", ['members' => []], $this->csrfHeader());
        self::assertResponseIsSuccessful();

        $this->logOut();
        $this->logIn($member);
        $this->json('GET', '/api/v1/admin/roles');
        self::assertResponseStatusCodeSame(
            403,
            'Removing a member must invalidate THEIR cache. Invalidating only the new list '
            .'leaves the person who left still holding the access, which is a revocation that '
            .'silently did not happen.',
        );
    }

    public function testItListsTheCurrentMembers(): void
    {
        $admin = $this->groupAdmin();
        $this->grantRolePermission(self::ROLE, PermissionCatalog::GROUP_READ);
        $member = $this->createUser('listed-member');
        $this->logIn($admin);
        $id = $this->createGroup('listable');

        $this->json('PUT', "/api/v1/admin/groups/{$id}/members", [
            'members' => [$member->id()->toRfc4122()],
        ], $this->csrfHeader());
        self::assertResponseIsSuccessful();

        $this->json('GET', "/api/v1/admin/groups/{$id}/members");

        self::assertResponseIsSuccessful();
        self::assertSame([$member->username()], $this->column($this->pageJson()['items'], 'username'));
    }

    public function testItRefusesAMemberThatDoesNotExist(): void
    {
        $this->logIn($this->groupAdmin());
        $id = $this->createGroup('bad-member');

        $this->json('PUT', "/api/v1/admin/groups/{$id}/members", [
            'members' => ['01920000-0000-7000-8000-000000000000'],
        ], $this->csrfHeader());

        self::assertResponseStatusCodeSame(422);
    }

    private function createGroup(string $name): string
    {
        $this->json('POST', '/api/v1/admin/groups', ['name' => $name], $this->csrfHeader());
        self::assertResponseStatusCodeSame(201);

        return $this->responseString('id');
    }

    private function groupAdmin(): User
    {
        $caller = $this->createUser('group-admin');
        $this->assignRole($caller, self::ROLE);
        $this->assignRole($this->createUser('carried-seed'), self::CARRIED);

        foreach ([
            PermissionCatalog::GROUP_CREATE,
            PermissionCatalog::GROUP_UPDATE,
            PermissionCatalog::GROUP_DELETE,
            PermissionCatalog::ROLE_READ,
        ] as $permission) {
            $this->grantRolePermission(self::ROLE, $permission);
        }

        return $caller;
    }
}
