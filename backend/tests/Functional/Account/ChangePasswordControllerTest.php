<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Tests\Functional\ApiTestCase;

/**
 * POST /api/v1/auth/change-password.
 *
 * Self-service password rotation: the signed-in caller proves they still know the current
 * password, then sets a new one. The current session is left intact (ADR-0024).
 */
final class ChangePasswordControllerTest extends ApiTestCase
{
    private const string NEW_PASSWORD = 'a-completely-different-passphrase-77';

    public function testItRejectsAnAnonymousCaller(): void
    {
        $this->json('POST', '/api/v1/auth/change-password', [
            'currentPassword' => self::PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testItRefusesTheWrongCurrentPassword(): void
    {
        $this->logIn($this->createUser('changer'));

        $this->json('POST', '/api/v1/auth/change-password', [
            'currentPassword' => 'definitely-not-the-current-password',
            'newPassword' => self::NEW_PASSWORD,
        ], $this->csrfHeader());

        // Same status as a bad login: a stolen session cookie must not become a way to take
        // over the account, so the current password is re-checked (ADR-0024).
        self::assertResponseStatusCodeSame(401);
    }

    public function testItRefusesAWeakNewPassword(): void
    {
        $this->logIn($this->createUser('changer'));

        $this->json('POST', '/api/v1/auth/change-password', [
            'currentPassword' => self::PASSWORD,
            'newPassword' => 'short',
        ], $this->csrfHeader());

        self::assertResponseStatusCodeSame(422);
    }

    public function testItChangesThePasswordAndLeavesTheCurrentSessionValid(): void
    {
        $user = $this->createUser('changer');
        $this->logIn($user);

        $this->json('POST', '/api/v1/auth/change-password', [
            'currentPassword' => self::PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ], $this->csrfHeader());

        self::assertResponseStatusCodeSame(204);

        // The current session is not revoked — the caller is using it (ADR-0024).
        $this->json('GET', '/api/v1/auth/me', [], $this->csrfHeader());
        self::assertResponseIsSuccessful();

        // The new password is now the credential; the old one no longer works.
        $this->logOut();
        $this->json('POST', '/api/v1/auth/login', [
            'username' => $user->username(),
            'password' => self::NEW_PASSWORD,
        ]);
        self::assertResponseIsSuccessful();

        $this->logOut();
        $this->json('POST', '/api/v1/auth/login', [
            'username' => $user->username(),
            'password' => self::PASSWORD,
        ]);
        self::assertResponseStatusCodeSame(401);
    }
}
