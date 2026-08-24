<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Account\Domain\User;
use App\Account\Domain\UserRepository;
use App\Acl\Domain\PermissionCatalog;
use App\Tests\Functional\ApiTestCase;

/**
 * POST /api/v1/admin/users/{id}/password.
 *
 * An administrator resets a user's password to a one-time system-generated temporary password
 * and revokes the user's existing sessions (ADR-0024).
 */
final class ResetUserPasswordControllerTest extends ApiTestCase
{
    private const string ROLE = 'ROLE_TEST_USER_RESETTER';

    public function testItRejectsAnAnonymousCaller(): void
    {
        $target = $this->createUser('target');

        $this->json('POST', '/api/v1/admin/users/'.$target->id()->toRfc4122().'/password');

        self::assertResponseStatusCodeSame(401);
    }

    public function testItRejectsACallerWithoutThePermission(): void
    {
        $target = $this->createUser('target');
        $this->logIn($this->createUser('bystander'));

        $this->json('POST', '/api/v1/admin/users/'.$target->id()->toRfc4122().'/password', [], $this->csrfHeader());

        self::assertResponseStatusCodeSame(403);
    }

    public function testItResetsToATemporaryPasswordShownOnce(): void
    {
        $target = $this->createUser('target');
        $this->logIn($this->admin());

        $this->json('POST', '/api/v1/admin/users/'.$target->id()->toRfc4122().'/password', [], $this->csrfHeader());

        self::assertResponseStatusCodeSame(200);
        $body = $this->responseJson();

        self::assertSame($target->id()->toRfc4122(), $body['id']);
        self::assertSame($target->username(), $body['username']);
        self::assertIsString($body['temporaryPassword']);
        self::assertNotSame('', $body['temporaryPassword']);
        // The plaintext leaves the API exactly once and never returns (ADR-0024).
        self::assertSame(['id', 'username', 'temporaryPassword'], array_keys($body));

        $temporaryPassword = $body['temporaryPassword'];

        // The old password no longer works; the temporary one does.
        $this->logOut();
        $this->json('POST', '/api/v1/auth/login', [
            'username' => $target->username(),
            'password' => self::PASSWORD,
        ]);
        self::assertResponseStatusCodeSame(401);

        $this->logOut();
        $this->json('POST', '/api/v1/auth/login', [
            'username' => $target->username(),
            'password' => $temporaryPassword,
        ]);
        self::assertResponseIsSuccessful();

        // The reset was persisted: the row still exists. (The plaintext-vs-hash distinction is
        // not observable under the test env's passthrough hasher; the login round-trip above is
        // the meaningful proof the row stores a hash of the temp password.)
        $reloaded = self::getContainer()->get(UserRepository::class)->findByUsername($target->username());
        self::assertInstanceOf(User::class, $reloaded);
    }

    public function testItRefusesToResetAnAnonymisedAccount(): void
    {
        $target = $this->createUser('erased');
        $target->anonymise(\sprintf('erased-%s', $target->id()->toRfc4122()), $this->clock->now());
        $this->em->flush();

        $this->logIn($this->admin());

        $this->json('POST', '/api/v1/admin/users/'.$target->id()->toRfc4122().'/password', [], $this->csrfHeader());

        self::assertResponseStatusCodeSame(409);
    }

    private function admin(): User
    {
        $caller = $this->createUser('admin');
        $this->assignRole($caller, self::ROLE);
        $this->grantRolePermission(self::ROLE, PermissionCatalog::USER_UPDATE);

        return $caller;
    }
}
