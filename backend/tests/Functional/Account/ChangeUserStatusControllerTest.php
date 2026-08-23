<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Account\Domain\User;
use App\Account\Domain\UserStatus;
use App\Acl\Domain\PermissionCatalog;
use App\Tests\Functional\ApiTestCase;

/**
 * PATCH /api/v1/admin/users/{id}/status.
 */
final class ChangeUserStatusControllerTest extends ApiTestCase
{
    private const string ROLE = 'ROLE_TEST_STATUS_CHANGER';

    public function testItRejectsAnAnonymousCaller(): void
    {
        $this->json('PATCH', $this->url($this->createUser('target')), ['status' => 'suspended']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testItRejectsACallerWithoutThePermission(): void
    {
        $this->logIn($this->createUser('nobody'));

        $this->json('PATCH', $this->url($this->createUser('target')), ['status' => 'suspended'], $this->csrfHeader());

        self::assertResponseStatusCodeSame(403);
    }

    public function testItSuspendsAndReinstates(): void
    {
        $target = $this->createUser('target');
        $this->logIn($this->changer());

        $this->json('PATCH', $this->url($target), ['status' => 'suspended'], $this->csrfHeader());
        self::assertResponseIsSuccessful();
        self::assertSame(UserStatus::Suspended, $this->reload($target)->status());

        $this->json('PATCH', $this->url($target), ['status' => 'active'], $this->csrfHeader());
        self::assertResponseIsSuccessful();
        self::assertSame(UserStatus::Active, $this->reload($target)->status());
    }

    /**
     * A suspended user must not keep working until their access token expires. Blocking the
     * next sign-in is not enough — that is up to ten minutes of access somebody just withdrew.
     */
    public function testSuspensionEndsTheTargetsExistingSession(): void
    {
        $target = $this->createUser('target');
        $this->logIn($target);
        $this->json('GET', '/api/v1/auth/me');
        self::assertResponseIsSuccessful();

        $targetCookies = $this->client->getCookieJar()->all();

        $this->logOut();
        $this->logIn($this->changer());
        $this->json('PATCH', $this->url($target), ['status' => 'suspended'], $this->csrfHeader());
        self::assertResponseIsSuccessful();

        // The refresh cookie is dead, so the session cannot be renewed.
        $this->logOut();
        foreach ($targetCookies as $cookie) {
            $this->client->getCookieJar()->set($cookie);
        }

        $this->json('POST', '/api/v1/auth/refresh', [], $this->csrfHeader());
        self::assertResponseStatusCodeSame(401, 'suspension must revoke the refresh token family');
    }

    public function testItRefusesToChangeYourOwnStatus(): void
    {
        $changer = $this->changer();
        $this->logIn($changer);

        $this->json('PATCH', $this->url($changer), ['status' => 'suspended'], $this->csrfHeader());

        self::assertResponseStatusCodeSame(409);
    }

    public function testItRefusesAStatusThatIsNotAnAdministrativeDestination(): void
    {
        $this->logIn($this->changer());

        $this->json('PATCH', $this->url($this->createUser('target')), ['status' => 'anonymised'], $this->csrfHeader());

        self::assertResponseStatusCodeSame(422, 'erasure is a different endpoint, with its own guards');
    }

    private function url(User $target): string
    {
        return '/api/v1/admin/users/'.$target->id()->toRfc4122().'/status';
    }

    private function changer(): User
    {
        $caller = $this->createUser('changer');
        $this->assignRole($caller, self::ROLE);
        $this->grantRolePermission(self::ROLE, PermissionCatalog::USER_UPDATE);

        return $caller;
    }
}
