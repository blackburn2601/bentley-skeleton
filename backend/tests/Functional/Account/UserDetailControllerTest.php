<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Account\Domain\User;
use App\Acl\Domain\PermissionCatalog;
use App\Tests\Functional\ApiTestCase;

/**
 * The single-user endpoints: detail, email edit, session revocation.
 */
final class UserDetailControllerTest extends ApiTestCase
{
    private const string ROLE = 'ROLE_TEST_USER_EDITOR';

    public function testDetailRejectsAnAnonymousCaller(): void
    {
        $this->json('GET', '/api/v1/admin/users/'.$this->createUser('target')->id()->toRfc4122());

        self::assertResponseStatusCodeSame(401);
    }

    public function testDetailRejectsACallerWithoutThePermission(): void
    {
        $this->logIn($this->createUser('nobody'));

        $this->json('GET', '/api/v1/admin/users/'.$this->createUser('target')->id()->toRfc4122());

        self::assertResponseStatusCodeSame(403);
    }

    public function testDetailNeverExposesSecrets(): void
    {
        $target = $this->createUser('target');
        $this->logIn($this->editor());

        $this->json('GET', '/api/v1/admin/users/'.$target->id()->toRfc4122());

        self::assertResponseIsSuccessful();
        $body = $this->responseJson();

        self::assertSame($target->email(), $body['email']);
        self::assertArrayNotHasKey('passwordHash', $body);
        self::assertArrayNotHasKey('totpSecretEncrypted', $body);
    }

    /**
     * The question an administrator actually arrives with: not "what roles?" but "what can
     * they do?". Those differ whenever a group carries a role, which is the normal case — and
     * the list must come from the resolver, not be reassembled here (INV-19).
     */
    public function testDetailReportsPermissionsInheritedThroughARole(): void
    {
        $target = $this->createUser('target');
        $this->assignRole($target, self::ROLE);
        $this->grantRolePermission(self::ROLE, PermissionCatalog::AUDIT_READ);

        $this->logIn($this->editor());
        $this->json('GET', '/api/v1/admin/users/'.$target->id()->toRfc4122());

        self::assertResponseIsSuccessful();

        self::assertContains(self::ROLE, $this->responseList('access.roles'));
        self::assertContains(
            PermissionCatalog::AUDIT_READ,
            $this->responseList('access.effectivePermissions'),
        );
    }

    public function testItRefusesAMissingUser(): void
    {
        $this->logIn($this->editor());

        $this->json('GET', '/api/v1/admin/users/01920000-0000-7000-8000-000000000000');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * A changed address is unproven, so verification is reset. Leaving it verified would let a
     * typo become an address that password resets are sent to.
     */
    public function testChangingTheEmailResetsVerification(): void
    {
        $target = $this->createUser('target', verified: true);
        $this->logIn($this->editor());
        $newEmail = 'moved-'.bin2hex(random_bytes(4)).'@functional.test';

        $this->json(
            'PATCH',
            '/api/v1/admin/users/'.$target->id()->toRfc4122(),
            ['email' => $newEmail],
            $this->csrfHeader(),
        );

        self::assertResponseIsSuccessful();
        self::assertSame($newEmail, $this->responseJson()['email']);
        self::assertFalse($this->responseJson()['emailVerified']);

        $reloaded = $this->reload($target);
        self::assertSame($newEmail, $reloaded->email());
        self::assertFalse($reloaded->isEmailVerified());
    }

    public function testItRefusesAnEmailAlreadyInUse(): void
    {
        $target = $this->createUser('target');
        $other = $this->createUser('other');
        $this->logIn($this->editor());

        $this->json(
            'PATCH',
            '/api/v1/admin/users/'.$target->id()->toRfc4122(),
            ['email' => $other->email()],
            $this->csrfHeader(),
        );

        self::assertResponseStatusCodeSame(409);
    }

    public function testRevokingSessionsEndsThemWithoutSuspendingTheAccount(): void
    {
        $target = $this->createUser('target');
        $this->logIn($target);
        $targetCookies = $this->client->getCookieJar()->all();

        $this->logOut();
        $this->logIn($this->editor());
        $this->json(
            'POST',
            '/api/v1/admin/users/'.$target->id()->toRfc4122().'/sessions/revoke',
            [],
            $this->csrfHeader(),
        );

        self::assertResponseIsSuccessful();
        self::assertGreaterThanOrEqual(1, $this->responseJson()['sessionsRevoked']);

        // The session is gone…
        $this->logOut();
        foreach ($targetCookies as $cookie) {
            $this->client->getCookieJar()->set($cookie);
        }
        $this->json('POST', '/api/v1/auth/refresh', [], $this->csrfHeader());
        self::assertResponseStatusCodeSame(401);

        // …but the account is untouched, so they can simply sign in again.
        $this->logOut();
        $this->logIn($this->reload($target));
    }

    private function editor(): User
    {
        $caller = $this->createUser('user-editor');
        $this->assignRole($caller, self::ROLE);
        $this->grantRolePermission(self::ROLE, PermissionCatalog::USER_READ);
        $this->grantRolePermission(self::ROLE, PermissionCatalog::USER_UPDATE);

        return $caller;
    }
}
