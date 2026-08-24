<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Account\Domain\User;
use App\Tests\Functional\ApiTestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * POST /api/v1/account/mfa/enrol — self-service enrollment start (ADR-0026).
 *
 * The enrollment flow lives in {@see TwoFactorFlowTest}; these are the per-endpoint
 * access-control checks. Enrol needs `account.update`, which the baseline role grants every
 * registered user — so the refused caller is one constructed with no role at all.
 */
final class EnrolTwoFactorControllerTest extends ApiTestCase
{
    public function testItRejectsAnAnonymousCaller(): void
    {
        $this->json('POST', '/api/v1/account/mfa/enrol');

        self::assertResponseStatusCodeSame(401);
    }

    public function testItRejectsACallerWithoutAccountUpdate(): void
    {
        $this->logIn($this->userWithoutAccountUpdate('bystander'));

        $this->json('POST', '/api/v1/account/mfa/enrol', [], $this->csrfHeader());

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * A user who holds no role, so the baseline `account.update` grant never reaches them.
     *
     * {@see createUser()} assigns the default role (and with it account.update); a bystander
     * for an account-update endpoint must be built without it. The baseline roles and
     * permissions are still seeded so the rest of the suite resolves them.
     */
    private function userWithoutAccountUpdate(string $label): User
    {
        $hasher = self::getContainer()
            ->get(PasswordHasherFactoryInterface::class)
            ->getPasswordHasher('app_account_password');

        $user = new User(
            \sprintf('%s-%s', $label, bin2hex(random_bytes(5))),
            $hasher->hash(self::PASSWORD),
            $this->clock->now(),
        );
        $this->em->persist($user);
        $this->em->flush();

        $this->ensureBaselineRole();

        return $user;
    }
}
