<?php

declare(strict_types=1);

namespace App\Tests\Functional\Acl;

use App\Account\Domain\User;
use App\Acl\Domain\PermissionCatalog;
use App\Acl\Domain\Role;
use App\Tests\Functional\ApiTestCase;

/**
 * The role write endpoints: create, update, set-permissions, delete.
 *
 * One class rather than four, because these four endpoints share a fixture and are only
 * meaningful together — the interesting assertions are about what they refuse.
 */
final class RoleWriteControllerTest extends ApiTestCase
{
    private const string ROLE = 'ROLE_TEST_ROLE_ADMIN';

    public function testAnonymousCallersAreRefused(): void
    {
        $this->json('POST', '/api/v1/admin/roles', ['name' => 'ROLE_X']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testACallerWithoutThePermissionIsRefused(): void
    {
        $this->logIn($this->createUser('nobody'));

        $this->json('POST', '/api/v1/admin/roles', ['name' => 'ROLE_X'], $this->csrfHeader());

        self::assertResponseStatusCodeSame(403);
    }

    public function testItCreatesARoleCarryingNothing(): void
    {
        $this->logIn($this->roleAdmin());

        $this->json('POST', '/api/v1/admin/roles', [
            'name' => 'ROLE_NEWLY_MADE',
            'description' => 'Made by a test',
        ], $this->csrfHeader());

        self::assertResponseStatusCodeSame(201);
        $body = $this->responseJson();
        self::assertSame('ROLE_NEWLY_MADE', $body['name']);
        self::assertSame([], $body['permissions'], 'a new role grants nothing until filled');
    }

    public function testItRefusesADuplicateName(): void
    {
        $this->logIn($this->roleAdmin());
        $this->json('POST', '/api/v1/admin/roles', ['name' => 'ROLE_DUPLICATE'], $this->csrfHeader());
        self::assertResponseStatusCodeSame(201);

        $this->json('POST', '/api/v1/admin/roles', ['name' => 'ROLE_DUPLICATE'], $this->csrfHeader());

        self::assertResponseStatusCodeSame(409);
    }

    public function testItRefusesANameThatIsNotShapedLikeARole(): void
    {
        $this->logIn($this->roleAdmin());

        $this->json('POST', '/api/v1/admin/roles', ['name' => 'not-a-role'], $this->csrfHeader());

        self::assertResponseStatusCodeSame(422);
    }

    public function testItReplacesThePermissionSet(): void
    {
        $this->logIn($this->roleAdmin());
        $roleId = $this->createRole('ROLE_FILLABLE');

        $this->json('PUT', "/api/v1/admin/roles/{$roleId}/permissions", [
            'permissions' => [PermissionCatalog::USER_READ, PermissionCatalog::AUDIT_READ],
        ], $this->csrfHeader());

        self::assertResponseIsSuccessful();
        self::assertSame(
            [PermissionCatalog::USER_READ, PermissionCatalog::AUDIT_READ],
            $this->responseJson()['permissions'],
        );

        // PUT replaces; it is not a delta.
        $this->json('PUT', "/api/v1/admin/roles/{$roleId}/permissions", [
            'permissions' => [PermissionCatalog::AUDIT_READ],
        ], $this->csrfHeader());

        self::assertSame([PermissionCatalog::AUDIT_READ], $this->responseJson()['permissions']);
    }

    /**
     * The escalation ceiling — the reason `permission.grant` is not a synonym for super admin.
     *
     * Without it, a caller attaches any permission to a role they hold, and awards themselves
     * the rest of the system in two requests.
     */
    public function testItRefusesToGrantAPermissionTheCallerDoesNotHold(): void
    {
        $this->logIn($this->roleAdmin());
        $roleId = $this->createRole('ROLE_ESCALATION_ATTEMPT');

        $this->json('PUT', "/api/v1/admin/roles/{$roleId}/permissions", [
            'permissions' => [PermissionCatalog::USER_DELETE],
        ], $this->csrfHeader());

        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString(
            PermissionCatalog::USER_DELETE,
            $this->responseString('detail'),
        );
    }

    public function testItRefusesAPermissionSetOnTheSuperAdminRole(): void
    {
        $this->logIn($this->roleAdmin());
        $this->ensureBaselineRole();
        $superAdmin = $this->em->getRepository(Role::class)->findOneBy(['name' => Role::SUPER_ADMIN]);
        self::assertInstanceOf(Role::class, $superAdmin);

        $this->json('PUT', '/api/v1/admin/roles/'.$superAdmin->id()->toRfc4122().'/permissions', [
            'permissions' => [PermissionCatalog::USER_READ],
        ], $this->csrfHeader());

        self::assertResponseStatusCodeSame(409);
    }

    public function testItRefusesToDeleteABaselineRole(): void
    {
        $this->logIn($this->roleAdmin());
        $this->ensureBaselineRole();
        $baseline = $this->em->getRepository(Role::class)->findOneBy(['name' => Role::DEFAULT_USER]);
        self::assertInstanceOf(Role::class, $baseline);

        $this->json('DELETE', '/api/v1/admin/roles/'.$baseline->id()->toRfc4122(), [], $this->csrfHeader());

        self::assertResponseStatusCodeSame(409);
    }

    public function testItDeletesAnOrdinaryRole(): void
    {
        $this->logIn($this->roleAdmin());
        $roleId = $this->createRole('ROLE_DISPOSABLE');

        $this->json('DELETE', "/api/v1/admin/roles/{$roleId}", [], $this->csrfHeader());

        self::assertResponseIsSuccessful();
        self::assertNull($this->em->getRepository(Role::class)->findOneBy(['name' => 'ROLE_DISPOSABLE']));
    }

    public function testItEditsTheDescription(): void
    {
        $this->logIn($this->roleAdmin());
        $roleId = $this->createRole('ROLE_DESCRIBABLE');

        $this->json('PATCH', "/api/v1/admin/roles/{$roleId}", ['description' => 'Now explained'], $this->csrfHeader());

        self::assertResponseIsSuccessful();
        self::assertSame('Now explained', $this->responseJson()['description']);
        self::assertSame('ROLE_DESCRIBABLE', $this->responseJson()['name'], 'the name is not editable');
    }

    private function createRole(string $name): string
    {
        $this->json('POST', '/api/v1/admin/roles', ['name' => $name], $this->csrfHeader());
        self::assertResponseStatusCodeSame(201);

        return $this->responseString('id');
    }

    /** Holds every role permission, plus the two it is allowed to hand out. */
    private function roleAdmin(): User
    {
        $caller = $this->createUser('role-admin');
        $this->assignRole($caller, self::ROLE);

        foreach ([
            PermissionCatalog::ROLE_CREATE,
            PermissionCatalog::ROLE_UPDATE,
            PermissionCatalog::ROLE_DELETE,
            PermissionCatalog::PERMISSION_GRANT,
            PermissionCatalog::USER_READ,
            PermissionCatalog::AUDIT_READ,
        ] as $permission) {
            $this->grantRolePermission(self::ROLE, $permission);
        }

        return $caller;
    }
}
