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
        $this->json('POST', '/api/v1/admin/users', ['username' => 'nobody']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testItRejectsACallerWithoutThePermission(): void
    {
        $this->logIn($this->createUser('nobody'));

        $this->json('POST', '/api/v1/admin/users', ['username' => 'someone'], $this->csrfHeader());

        self::assertResponseStatusCodeSame(403);
    }

    public function testItCreatesAnActiveAccountAndReturnsTheTemporaryPasswordOnce(): void
    {
        $this->logIn($this->creator());
        $username = 'created-'.bin2hex(random_bytes(4));

        $this->json('POST', '/api/v1/admin/users', ['username' => $username], $this->csrfHeader());

        self::assertResponseStatusCodeSame(201);
        $body = $this->responseJson();

        self::assertSame($username, $body['username']);
        self::assertSame(UserStatus::Active->value, $body['status']);
        // The one-time temporary password is in the body so the administrator can hand it over
        // out-of-band. It is never persisted, never logged, and never returned again (ADR-0024).
        self::assertIsString($body['temporaryPassword']);
        self::assertNotSame('', $body['temporaryPassword']);
        self::assertSame(['id', 'username', 'status', 'temporaryPassword'], array_keys($body));

        // The account exists and is active.
        $created = self::getContainer()->get(UserRepository::class)->findByUsername($username);
        self::assertInstanceOf(User::class, $created);
        self::assertSame(UserStatus::Active, $created->status());

        // The temp password the administrator was handed actually authenticates the new account.
        // That round-trip through hash+verify is the real proof the row stores a hash *of this
        // password — the plaintext-vs-hash distinction is not observable under the test env's
        // passthrough hasher (security.yaml `when@test`); it holds under the production argon2id
        // hasher, which is what matters.
        $this->logOut();
        $this->json('POST', '/api/v1/auth/login', [
            'username' => $username,
            'password' => $body['temporaryPassword'],
        ]);
        self::assertResponseIsSuccessful();
    }

    /**
     * An admin form must say so — otherwise the operator waits for an account that never appears.
     */
    public function testItRefusesADuplicateUsernameExplicitly(): void
    {
        $existing = $this->createUser('taken');
        $this->logIn($this->creator());

        $this->json('POST', '/api/v1/admin/users', ['username' => $existing->username()], $this->csrfHeader());

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString($existing->username(), $this->responseString('detail'));
    }

    public function testItRefusesAUsernameOutsideTheCharset(): void
    {
        $this->logIn($this->creator());

        $this->json('POST', '/api/v1/admin/users', ['username' => 'has space'], $this->csrfHeader());

        self::assertResponseStatusCodeSame(422);
    }

    public function testItRefusesAUsernameThatIsTooShort(): void
    {
        $this->logIn($this->creator());

        $this->json('POST', '/api/v1/admin/users', ['username' => 'ab'], $this->csrfHeader());

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
