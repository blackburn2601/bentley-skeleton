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
 * @responsibility Clears a user's TOTP factor with its recovery codes as an administrator.
 *
 * A full reset: the secret, the recovery codes, and the administrator's requirement all go.
 * The user is back on the ADR-0024 floor — no MFA at all — and an administrator can re-require
 * it afterwards. Every session is revoked (high severity, audited), so no `amr: ['totp']`
 * token outlives the reset.
 */
final readonly class AdminResetTwoFactorService
{
    public function __construct(
        private UserRepository $users,
        private MfaRecoveryCodeRepository $recoveryCodes,
        private RevokeAllSessionsService $revokeAllSessions,
        private AuditFacade $audit,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(Uuid $userId, Uuid $changedBy): void
    {
        $user = $this->users->findById($userId);

        if (!$user instanceof User) {
            throw AccountException::noSuchAccount();
        }

        $user->clearTotpSecret();
        $user->clearMfaRequirement();
        $this->recoveryCodes->deleteAllForUser($user->id());
        $this->em->flush();

        ($this->revokeAllSessions)($user->id());

        $this->audit->record(SecurityEventType::MfaResetByAdmin, $changedBy, ['subjectId' => $user->id()->toRfc4122()]);
    }
}
