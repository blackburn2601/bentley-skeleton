<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Account\Domain\User;
use App\Tests\Functional\ApiTestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * DELETE /api/v1/account/mfa — self-service factor removal (ADR-0026).
 *
 * The disable flow lives in {@see TwoFactorFlowTest}; these are the per-endpoint access-control
 * checks. Like enrol/confirm, disable needs `account.update`, so the refused caller is one
 * built without the baseline role.
 */
final class DisableTwoFactorControllerTest extends ApiTestCase
{
    public function testItRejectsAnAnonymousCaller(): void
    {
        $this->json('DELETE', '/api/v1/account/mfa');

        self::assertResponseStatusCodeSame(401);
    }

    public function testItRejectsACallerWithoutAccountUpdate(): void
    {
        $this->logIn($this->userWithoutAccountUpdate('bystander'));

        $this->json('DELETE', '/api/v1/account/mfa', [], $this->csrfHeader());

        self::assertResponseStatusCodeSame(403);
    }

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
