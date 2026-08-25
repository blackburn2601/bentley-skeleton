<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Account\Domain\User;
use App\Tests\Functional\ApiTestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * POST /api/v1/account/mfa/confirm — activates a provisional factor (ADR-0026).
 *
 * The confirm flow and recovery-code minting live in {@see TwoFactorFlowTest}; these are the
 * per-endpoint access-control checks. The `account.update` guard runs before the controller
 * body, so a caller without the permission is refused before the no-enrollment-in-progress
 * (409) path can be reached.
 */
final class ConfirmTwoFactorControllerTest extends ApiTestCase
{
    public function testItRejectsAnAnonymousCaller(): void
    {
        $this->json('POST', '/api/v1/account/mfa/confirm', ['code' => '123456']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testItRejectsACallerWithoutAccountUpdate(): void
    {
        $this->logIn($this->userWithoutAccountUpdate('bystander'));

        $this->json('POST', '/api/v1/account/mfa/confirm', ['code' => '123456'], $this->csrfHeader());

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
