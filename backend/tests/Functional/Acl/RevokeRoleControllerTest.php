<?php

declare(strict_types=1);

namespace App\Tests\Functional\Acl;

use App\Account\Domain\User;
use App\Acl\Domain\PermissionCatalog;
use App\Tests\Functional\ApiTestCase;

/**
 * DELETE /api/v1/admin/users/{id}/roles/{roleName}.
 *
 * Revocation is the security-relevant direction: a grant that does not apply is an
 * inconvenience, access that is not withdrawn is a hole.
 */
final class RevokeRoleControllerTest extends ApiTestCase
{
    private const string REVOKER_ROLE = 'ROLE_TEST_REVOKER';
    private const string TARGET_ROLE = 'ROLE_TEST_REVOCABLE';

    public function testItRejectsAnAnonymousCaller(): void
    {
        $this->json('DELETE', $this->url($this->createUser('target')));

        self::assertResponseStatusCodeSame(401);
    }

    public function testItRejectsACallerWithoutTheRevokePermission(): void
    {
        $this->logIn($this->createUser('nobody'));

        $this->json('DELETE', $this->url($this->createUser('target')), [], $this->csrfHeader());

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * The counterpart of the grant test: access already held must disappear on the next
     * request, without the target doing anything.
     */
    public function testARevocationTakesEffectOnTheTargetsNextRequest(): void
    {
        $revoker = $this->revoker();
        $target = $this->createUser('target');
        $this->grantRolePermission(self::TARGET_ROLE, PermissionCatalog::ROLE_READ);
        $this->assignRole($target, self::TARGET_ROLE);

        $this->logIn($target);
        $this->json('GET', '/api/v1/admin/roles');
        self::assertResponseIsSuccessful('the target must start WITH role.read');

        $targetCookies = $this->client->getCookieJar()->all();

        $this->logOut();
        $this->logIn($revoker);
        $this->json('DELETE', $this->url($target), [], $this->csrfHeader());
        self::assertResponseIsSuccessful();

        // Same cookies, same token, no re-authentication.
        $this->logOut();
        foreach ($targetCookies as $cookie) {
            $this->client->getCookieJar()->set($cookie);
        }

        $this->json('GET', '/api/v1/admin/roles');
        self::assertResponseStatusCodeSame(
            403,
            'A revocation must apply to the next request. A 200 here is access that was '
            .'supposed to be withdrawn and was not.',
        );
    }

    public function testRevokingARoleTheUserNeverHeldSucceeds(): void
    {
        $revoker = $this->revoker();
        $this->logIn($revoker);

        $this->json('DELETE', $this->url($this->createUser('target')), [], $this->csrfHeader());

        self::assertResponseIsSuccessful('revocation is idempotent');
    }

    private function url(User $target): string
    {
        return \sprintf('/api/v1/admin/users/%s/roles/%s', $target->id()->toRfc4122(), self::TARGET_ROLE);
    }

    private function revoker(): User
    {
        $revoker = $this->createUser('revoker');
        $this->assignRole($revoker, self::REVOKER_ROLE);
        $this->grantRolePermission(self::REVOKER_ROLE, PermissionCatalog::PERMISSION_REVOKE);
        $this->assignRole($this->createUser('seed-holder'), self::TARGET_ROLE);

        return $revoker;
    }
}
