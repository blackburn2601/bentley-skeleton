<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Application\PasswordHasher;
use App\Account\Domain\AccountException;
use App\Account\Domain\User;
use App\Account\Domain\UserRepository;
use App\Audit\Application\AuditFacade;
use App\Shared\Domain\Clock;
use App\Shared\Domain\SecurityEventType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Changes the signed-in user's password after verifying the current one.
 *
 * The caller is already authenticated, so the current password is re-checked here purely to
 * keep a stolen session cookie from becoming a way to take over the account: a browser left
 * unlocked cannot silently rotate the password. The current session is left intact — the
 * caller is using it — and other sessions are not touched (ADR-0024).
 */
final readonly class ChangePasswordService
{
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $hasher,
        private AssertPasswordAcceptableService $assertAcceptable,
        private AuditFacade $audit,
        private Clock $clock,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(Uuid $userId, string $currentPassword, string $newPassword): void
    {
        $user = $this->users->findById($userId);

        if (!$user instanceof User) {
            // A valid session whose user has since been erased: there is nothing to change.
            throw AccountException::invalidToken();
        }

        if (!$this->hasher->verify($user->passwordHash(), $currentPassword)) {
            throw AccountException::invalidCredentials();
        }

        ($this->assertAcceptable)($newPassword, $user->username());

        $user->changePassword($this->hasher->hash($newPassword), $this->clock->now());
        $this->em->flush();

        $this->audit->record(SecurityEventType::PasswordChanged, $user->id());
    }
}
