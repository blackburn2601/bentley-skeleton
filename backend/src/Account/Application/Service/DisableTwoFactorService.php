<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Domain\AccountException;
use App\Account\Domain\MfaRecoveryCodeRepository;
use App\Account\Domain\User;
use App\Account\Domain\UserRepository;
use App\Audit\Application\AuditFacade;
use App\Shared\Domain\SecurityEventType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Removes the caller's enrolled TOTP factor with its recovery codes.
 *
 * The administrator's `mfaRequired` policy is left untouched: removing the device is the
 * user's choice, not a relaxation of policy. If the admin still requires MFA, the user must
 * re-enroll. Sessions that authenticated with this factor are revoked, so a stolen token does
 * not keep its `amr: ['totp']` claim for the refresh token's lifetime.
 */
final readonly class DisableTwoFactorService
{
    public function __construct(
        private UserRepository $users,
        private MfaRecoveryCodeRepository $recoveryCodes,
        private RevokeAllSessionsService $revokeAllSessions,
        private AuditFacade $audit,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(Uuid $userId): void
    {
        $user = $this->users->findById($userId);

        if (!$user instanceof User) {
            throw AccountException::invalidToken();
        }

        // Nothing to disable if neither a live nor a provisional secret exists. A provisional
        // secret alone can be cancelled here, which clears an abandoned enrollment.
        if (!$user->hasEnrolledTotp() && null === $user->totpSecretEncryptedProvisional()) {
            throw AccountException::noEnrolledTotp();
        }

        $user->clearTotpSecret();
        $this->recoveryCodes->deleteAllForUser($user->id());
        $this->em->flush();

        ($this->revokeAllSessions)($user->id());

        $this->audit->record(SecurityEventType::MfaDisabled, $user->id());
    }
}
