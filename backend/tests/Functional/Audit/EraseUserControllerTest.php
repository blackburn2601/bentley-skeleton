<?php

declare(strict_types=1);

namespace App\Tests\Functional\Audit;

use App\Account\Domain\User;
use App\Account\Domain\UserStatus;
use App\Acl\Domain\PermissionCatalog;
use App\Tests\Functional\ApiTestCase;

/**
 * DELETE /api/v1/admin/users/{id}.
 */
final class EraseUserControllerTest extends ApiTestCase
{
    private const string ROLE = 'ROLE_TEST_ERASER';

    public function testItRejectsAnAnonymousCaller(): void
    {
        $this->json('DELETE', $this->url($this->createUser('target')));

        self::assertResponseStatusCodeSame(401);
    }

    public function testItRejectsACallerWithoutThePermission(): void
    {
        $this->logIn($this->createUser('nobody'));

        $this->json('DELETE', $this->url($this->createUser('target')), [], $this->csrfHeader());

        self::assertResponseStatusCodeSame(403);
    }

    public function testItAnonymisesRatherThanDeletes(): void
    {
        $target = $this->createUser('target');
        $originalUsername = $target->username();
        $this->logIn($this->eraser());

        $this->json('DELETE', $this->url($target), [], $this->csrfHeader());

        self::assertResponseIsSuccessful();
        self::assertTrue($this->responseJson()['erased']);

        // The row survives, because the audit trail references this id and must outlive the
        // erasure it records (ADR-0012).
        $erased = $this->reload($target);
        self::assertSame(UserStatus::Anonymised, $erased->status());
        self::assertNotSame($originalUsername, $erased->username());
    }

    public function testItRefusesToEraseYourOwnAccount(): void
    {
        $eraser = $this->eraser();
        $this->logIn($eraser);

        $this->json('DELETE', $this->url($eraser), [], $this->csrfHeader());

        self::assertResponseStatusCodeSame(409, 'self-erasure has its own endpoint, with its own confirmation');
    }

    private function url(User $target): string
    {
        return '/api/v1/admin/users/'.$target->id()->toRfc4122();
    }

    private function eraser(): User
    {
        $caller = $this->createUser('eraser');
        $this->assignRole($caller, self::ROLE);
        $this->grantRolePermission(self::ROLE, PermissionCatalog::USER_DELETE);

        return $caller;
    }
}
