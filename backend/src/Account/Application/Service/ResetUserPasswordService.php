<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Application\CreatedUser;
use App\Account\Application\PasswordHasher;
use App\Account\Domain\AccountException;
use App\Account\Domain\User;
use App\Account\Domain\UserRepository;
use App\Account\Domain\UserStatus;
use App\Audit\Application\AuditFacade;
use App\Shared\Domain\Clock;
use App\Shared\Domain\SecretGenerator;
use App\Shared\Domain\SecurityEventType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Resets a user's password to a new system-generated temporary password.
 *
 * The new temporary password is returned once for the administrator to hand over out-of-band,
 * and every existing session is revoked so the old credential stops working immediately. The
 * plaintext never persists and never logs (ADR-0024).
 */
final readonly class ResetUserPasswordService
{
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $hasher,
        private RevokeAllSessionsService $revokeSessions,
        private AuditFacade $audit,
        private SecretGenerator $secrets,
        private Clock $clock,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(Uuid $userId, Uuid $resetBy): CreatedUser
    {
        $user = $this->users->findById($userId);

        if (!$user instanceof User) {
            throw AccountException::noSuchAccount();
        }

        if (UserStatus::Anonymised === $user->status()) {
            throw AccountException::accountIsAnonymised();
        }

        $temporaryPassword = $this->secrets->generateTemporaryPassword();

        $user->changePassword($this->hasher->hash($temporaryPassword), $this->clock->now());
        $this->em->flush();

        // The old password is no longer the user's credential, so the sessions it minted must
        // not survive either — otherwise "reset" would not actually revoke access.
        ($this->revokeSessions)($user->id());

        $this->audit->record(SecurityEventType::PasswordReset, $resetBy, [
            'subjectId' => $user->id()->toRfc4122(),
            'resetByAdministrator' => true,
        ]);

        return new CreatedUser(
            $user->id()->toRfc4122(),
            $user->username(),
            $user->status()->value,
            $temporaryPassword,
        );
    }
}
