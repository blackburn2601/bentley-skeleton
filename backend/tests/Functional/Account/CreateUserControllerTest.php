<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Account\Domain\User;
use App\Account\Domain\UserRepository;
use App\Account\Domain\UserStatus;
use App\Acl\Domain\PermissionCatalog;
use App\Tests\Functional\ApiTestCase;

/**
 * POST /api/v1/admin/users.
 */
final class CreateUserControllerTest extends ApiTestCase
{
    private const string ROLE = 'ROLE_TEST_USER_CREATOR';

    public function testItRejectsAnAnonymousCaller(): void
    {
        $this->json('POST', '/api/v1/admin/users', ['email' => 'nobody@functional.test']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testItRejectsACallerWithoutThePermission(): void
    {
        $this->logIn($this->createUser('nobody'));

        $this->json('POST', '/api/v1/admin/users', ['email' => 'nobody@functional.test'], $this->csrfHeader());

        self::assertResponseStatusCodeSame(403);
    }

    public function testItCreatesAnActiveAccountWithoutEverReturningAPassword(): void
    {
        $this->logIn($this->creator());
        $email = 'created-'.bin2hex(random_bytes(4)).'@functional.test';

        $this->json('POST', '/api/v1/admin/users', ['email' => $email], $this->csrfHeader());

        self::assertResponseStatusCodeSame(201);
        $body = $this->responseJson();

        self::assertSame($email, $body['email']);
        self::assertSame(UserStatus::Active->value, $body['status']);
        self::assertTrue($body['passwordSetupEmailed']);

        // The whole point of the design: nobody, including the administrator who made the
        // account, is ever shown a password for it.
        self::assertSame(['id', 'email', 'status', 'passwordSetupEmailed'], array_keys($body));

        $created = self::getContainer()->get(UserRepository::class)->findByEmail($email);
        self::assertInstanceOf(User::class, $created);
        self::assertTrue($created->isEmailVerified(), 'the reset link proves address control');
    }

    /**
     * Unlike registration, which hides duplicates to avoid an enumeration oracle, an admin
     * form must say so — otherwise the operator waits for an account that never appears.
     */
    public function testItRefusesADuplicateEmailExplicitly(): void
    {
        $existing = $this->createUser('taken');
        $this->logIn($this->creator());

        $this->json('POST', '/api/v1/admin/users', ['email' => $existing->email()], $this->csrfHeader());

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString($existing->email(), $this->responseString('detail'));
    }

    public function testItRefusesAMalformedEmail(): void
    {
        $this->logIn($this->creator());

        $this->json('POST', '/api/v1/admin/users', ['email' => 'not-an-email'], $this->csrfHeader());

        self::assertResponseStatusCodeSame(422);
    }

    private function creator(): User
    {
        $caller = $this->createUser('creator');
        $this->assignRole($caller, self::ROLE);
        $this->grantRolePermission(self::ROLE, PermissionCatalog::USER_CREATE);

        return $caller;
    }
}
