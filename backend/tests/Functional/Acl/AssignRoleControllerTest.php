<?php

declare(strict_types=1);

namespace App\Tests\Functional\Acl;

use App\Account\Domain\User;
use App\Acl\Domain\PermissionCatalog;
use App\Tests\Functional\ApiTestCase;

/**
 * POST /api/v1/admin/users/{id}/roles.
 *
 * The last test here is the one that matters: it asserts the property ADR-0011 exists to
 * provide and INV-13 names — that a grant takes effect on the very next request, with no
 * re-authentication and no waiting out a cache.
 */
final class AssignRoleControllerTest extends ApiTestCase
{
    private const string GRANTER_ROLE = 'ROLE_TEST_GRANTER';
    private const string TARGET_ROLE = 'ROLE_TEST_AUDITOR';

    public function testItRejectsAnAnonymousCaller(): void
    {
        $this->json('POST', '/api/v1/admin/users/'.$this->createUser('target')->id()->toRfc4122().'/roles', [
            'role' => self::TARGET_ROLE,
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * `user.update` is not enough. Assigning a role is a privilege grant, so it is guarded by
     * `permission.grant` — someone who may edit a profile may not make anyone an administrator.
     */
    public function testItRejectsACallerWithoutTheGrantPermission(): void
    {
        $caller = $this->createUser('editor');
        $this->assignRole($caller, 'ROLE_TEST_EDITOR');
        $this->grantRolePermission('ROLE_TEST_EDITOR', PermissionCatalog::USER_UPDATE);
        $this->logIn($caller);

        $this->json(
            'POST',
            '/api/v1/admin/users/'.$this->createUser('target')->id()->toRfc4122().'/roles',
            ['role' => self::TARGET_ROLE],
            $this->csrfHeader(),
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testItRefusesARoleThatDoesNotExist(): void
    {
        $this->logIn($this->granter());

        $this->json(
            'POST',
            '/api/v1/admin/users/'.$this->createUser('target')->id()->toRfc4122().'/roles',
            ['role' => 'ROLE_NOT_A_REAL_ROLE'],
            $this->csrfHeader(),
        );

        self::assertResponseStatusCodeSame(404);
    }

    /** CSRF now covers every unsafe request under /api/v1 (ADR-0020), not three auth paths. */
    public function testItRefusesAWriteWithoutTheCsrfHeader(): void
    {
        $this->logIn($this->granter());

        $this->json(
            'POST',
            '/api/v1/admin/users/'.$this->createUser('target')->id()->toRfc4122().'/roles',
            ['role' => self::TARGET_ROLE],
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testItAssignsTheRole(): void
    {
        $this->logIn($this->granter());
        $target = $this->createUser('target');

        $this->json(
            'POST',
            '/api/v1/admin/users/'.$target->id()->toRfc4122().'/roles',
            ['role' => self::TARGET_ROLE],
            $this->csrfHeader(),
        );

        self::assertResponseStatusCodeSame(201);
        $body = $this->responseJson();
        self::assertSame(self::TARGET_ROLE, $body['role']);
        self::assertSame($target->id()->toRfc4122(), $body['userId']);
    }

    /**
     * INV-13, at the HTTP layer.
     *
     * The target signs in BEFORE the grant and never signs in again. Their access token is
     * minted without the permission, and it is the `perm_v` claim plus server-side resolution
     * that must make the new grant visible — not a fresh login.
     */
    public function testAGrantTakesEffectOnTheTargetsNextRequestWithoutReAuthenticating(): void
    {
        // The granter is built first because building it also creates TARGET_ROLE, and a
        // permission cannot be attached to a role that does not exist yet.
        $granter = $this->granter();
        $target = $this->createUser('target');
        $this->grantRolePermission(self::TARGET_ROLE, PermissionCatalog::ROLE_READ);

        // The target signs in first, then confirms they cannot read roles.
        $this->logIn($target);
        $this->json('GET', '/api/v1/admin/roles');
        self::assertResponseStatusCodeSame(403, 'the target must start without role.read');

        $targetCookies = $this->client->getCookieJar()->all();

        // A different administrator grants the role.
        $this->logOut();
        $this->logIn($granter);
        $this->json(
            'POST',
            '/api/v1/admin/users/'.$target->id()->toRfc4122().'/roles',
            ['role' => self::TARGET_ROLE],
            $this->csrfHeader(),
        );
        self::assertResponseStatusCodeSame(201);

        // Back as the target, on the SAME cookies — no second login.
        $this->logOut();
        foreach ($targetCookies as $cookie) {
            $this->client->getCookieJar()->set($cookie);
        }

        $this->json('GET', '/api/v1/admin/roles');
        self::assertResponseIsSuccessful(
            'A grant must take effect on the next request. A 403 here means acl_version was '
            .'not bumped, and revocation is equally broken.',
        );

        // And the advisory list the SPA hides controls with reflects it too (INV-19).
        $this->json('GET', '/api/v1/auth/me');
        $permissions = $this->responseJson()['permissions'];
        self::assertIsArray($permissions);
        self::assertContains(PermissionCatalog::ROLE_READ, $permissions);
    }

    private function granter(): User
    {
        $granter = $this->createUser('granter');
        $this->assignRole($granter, self::GRANTER_ROLE);
        $this->grantRolePermission(self::GRANTER_ROLE, PermissionCatalog::PERMISSION_GRANT);

        // The role that will be handed out has to exist before it can be assigned.
        $this->assignRole($this->createUser('seed-holder'), self::TARGET_ROLE);

        return $granter;
    }
}
