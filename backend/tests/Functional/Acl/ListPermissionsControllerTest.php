<?php

declare(strict_types=1);

namespace App\Tests\Functional\Acl;

use App\Account\Domain\User;
use App\Acl\Domain\PermissionCatalog;
use App\Tests\Functional\ApiTestCase;

/**
 * GET /api/v1/admin/permissions.
 */
final class ListPermissionsControllerTest extends ApiTestCase
{
    private const string ROLE = 'ROLE_TEST_PERM_READER';

    public function testItRejectsAnAnonymousCaller(): void
    {
        $this->json('GET', '/api/v1/admin/permissions');

        self::assertResponseStatusCodeSame(401);
    }

    public function testItRejectsACallerWithoutThePermission(): void
    {
        $this->logIn($this->createUser('nobody'));

        $this->json('GET', '/api/v1/admin/permissions');

        self::assertResponseStatusCodeSame(403);
    }

    public function testItReturnsTheEnvelopeForAPermittedCaller(): void
    {
        $this->logIn($this->permittedCaller());

        $this->json('GET', '/api/v1/admin/permissions');

        self::assertResponseIsSuccessful();
        $body = $this->pageJson();

        // Every collection returns the same four keys, paged or not (ADR-0019).
        self::assertCount($body['total'], $body['items']);
        self::assertGreaterThanOrEqual(1, $body['page']);
    }

    private function permittedCaller(): User
    {
        $caller = $this->createUser('perm-reader');
        $this->assignRole($caller, self::ROLE);
        $this->grantRolePermission(self::ROLE, PermissionCatalog::PERMISSION_READ);

        return $caller;
    }
}
