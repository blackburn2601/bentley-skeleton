<?php

declare(strict_types=1);

namespace App\Tests\Functional\Audit;

use App\Account\Domain\User;
use App\Acl\Domain\PermissionCatalog;
use App\Tests\Functional\ApiTestCase;

/**
 * GET /api/v1/admin/audit-events.
 */
final class ListSecurityEventsControllerTest extends ApiTestCase
{
    private const string ROLE = 'ROLE_TEST_AUDIT_READER';

    public function testItRejectsAnAnonymousCaller(): void
    {
        $this->json('GET', '/api/v1/admin/audit-events');

        self::assertResponseStatusCodeSame(401);
    }

    public function testItRejectsACallerWithoutThePermission(): void
    {
        $this->logIn($this->createUser('nobody'));

        $this->json('GET', '/api/v1/admin/audit-events');

        self::assertResponseStatusCodeSame(403);
    }

    public function testItReturnsTheEnvelopeForAPermittedCaller(): void
    {
        $this->logIn($this->permittedCaller());

        $this->json('GET', '/api/v1/admin/audit-events');

        self::assertResponseIsSuccessful();
        $body = $this->pageJson();

        // Every collection returns the same four keys, paged or not (ADR-0019).
        self::assertCount($body['total'], $body['items']);
        self::assertGreaterThanOrEqual(1, $body['page']);
    }

    private function permittedCaller(): User
    {
        $caller = $this->createUser('audit-reader');
        $this->assignRole($caller, self::ROLE);
        $this->grantRolePermission(self::ROLE, PermissionCatalog::AUDIT_READ);

        return $caller;
    }
}
