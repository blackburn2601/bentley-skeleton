<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Account\Domain\User;
use App\Acl\Domain\AclEffect;
use App\Acl\Domain\PermissionCatalog;
use App\Tests\Functional\ApiTestCase;

/**
 * GET /api/v1/admin/users.
 *
 * The interesting test here is not that the list works — it is that the list agrees with a
 * single-item permission check on every row. A collection endpoint that filters in PHP after
 * the query passes a naive happy-path test and still leaks, or hides, rows.
 */
final class ListUsersControllerTest extends ApiTestCase
{
    private const string ADMIN_ROLE = 'ROLE_TEST_USER_ADMIN';

    public function testItRejectsAnAnonymousCaller(): void
    {
        $this->json('GET', '/api/v1/admin/users');

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * A signed-in caller holding only the baseline role. `account.*` does not imply `user.*`
     * — reading your own profile is not reading everyone's.
     */
    public function testItRejectsACallerWithoutThePermission(): void
    {
        $this->logIn($this->createUser('nobody'));

        $this->json('GET', '/api/v1/admin/users');

        self::assertResponseStatusCodeSame(403);
        self::assertSame('application/problem+json', $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testItReturnsThePageAndItsEnvelopeForAPermittedCaller(): void
    {
        $caller = $this->permittedCaller();
        $this->logIn($caller);

        $this->json('GET', '/api/v1/admin/users?perPage=100');

        self::assertResponseIsSuccessful();
        $body = $this->pageJson();

        self::assertSame(1, $body['page']);
        self::assertSame(100, $body['perPage']);
        self::assertNotEmpty($body['items']);

        $row = $body['items'][0];
        self::assertSame(
            ['id', 'username', 'status', 'lockedUntil', 'createdAt'],
            array_keys($row),
        );

        // INV-05: the entity is not the contract. These two must never appear, whatever gets
        // added to the User entity later.
        self::assertArrayNotHasKey('passwordHash', $row);
        self::assertArrayNotHasKey('totpSecretEncrypted', $row);
    }

    /**
     * The test this endpoint exists to survive.
     *
     * An explicit object-level DENY must remove the row from the list, not merely from a
     * single-item check — and the total must agree, or the pager promises rows it cannot show.
     */
    public function testAnObjectLevelDenyRemovesThatUserFromTheList(): void
    {
        $caller = $this->permittedCaller();
        $hidden = $this->createUser('hidden');

        $this->logIn($caller);
        $this->json('GET', '/api/v1/admin/users?perPage=100');
        $before = $this->pageJson();
        self::assertContains($hidden->username(), $this->column($before['items'], 'username'));

        $this->grantOnObject($caller, User::class, $hidden->id(), PermissionCatalog::USER_READ, AclEffect::Deny);

        $this->logOut();
        $this->logIn($caller);
        $this->json('GET', '/api/v1/admin/users?perPage=100');
        $after = $this->pageJson();

        self::assertNotContains($hidden->username(), $this->column($after['items'], 'username'));
        self::assertSame(
            $before['total'] - 1,
            $after['total'],
            'The total must be computed over the same predicate as the rows, or the pager lies.',
        );
    }

    public function testItSearchesByUsername(): void
    {
        $caller = $this->permittedCaller();
        $needle = $this->createUser('findme');

        $this->logIn($caller);
        $this->json('GET', '/api/v1/admin/users?q='.urlencode($needle->username()));

        self::assertResponseIsSuccessful();
        self::assertSame([$needle->username()], $this->column($this->pageJson()['items'], 'username'));
    }

    public function testItRefusesAPageSizeAboveTheCap(): void
    {
        $this->logIn($this->permittedCaller());

        $this->json('GET', '/api/v1/admin/users?perPage=1000');

        self::assertResponseStatusCodeSame(422);
    }

    /** A caller holding `user.read` at class level, which is what a list endpoint requires. */
    private function permittedCaller(): User
    {
        $caller = $this->createUser('user-admin');
        $this->assignRole($caller, self::ADMIN_ROLE);
        $this->grantRolePermission(self::ADMIN_ROLE, PermissionCatalog::USER_READ);

        return $caller;
    }
}
