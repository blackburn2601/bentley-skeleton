<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Application\Totp;
use App\Account\Domain\AccountException;
use App\Account\Domain\User;
use App\Account\Domain\UserRepository;
use App\Audit\Application\AuditFacade;
use App\Shared\Domain\SecretEncryptor;
use App\Shared\Domain\SecurityEventType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Activates the provisional TOTP secret on a valid first code.
 *
 * On a correct code the provisional secret becomes live and a fresh set of single-use recovery
 * codes is minted. The plaintext recovery codes are returned once; only their hashes are kept.
 * Every session that authenticated before this factor existed is then revoked — they carry no
 * `amr` and would never be challenged, so they must re-login to pick up the new factor.
 */
final readonly class ConfirmTwoFactorService
{
    public function __construct(
        private UserRepository $users,
        private Totp $totp,
        private SecretEncryptor $encryptor,
        private MintRecoveryCodesService $mintRecoveryCodes,
        private RevokeAllSessionsService $revokeAllSessions,
        private AuditFacade $audit,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @return list<string> the plaintext recovery codes, shown exactly once
     */
    public function __invoke(Uuid $userId, string $code): array
    {
        $user = $this->users->findById($userId);

        if (!$user instanceof User) {
            throw AccountException::invalidToken();
        }

        $provisional = $user->totpSecretEncryptedProvisional();

        if (null === $provisional || '' === $provisional) {
            throw AccountException::noTotpEnrollmentInProgress();
        }

        if (!$this->totp->verify($this->encryptor->decrypt($provisional), $code)) {
            $this->audit->record(SecurityEventType::MfaChallengeFailed, $user->id());

            throw AccountException::invalidTwoFactorCode();
        }

        $user->confirmTotpEnrollment();
        $plaintextCodes = ($this->mintRecoveryCodes)($user);
        $this->em->flush();

        // The current access token still works until it expires, which is how the recovery
        // codes reach the client. The refresh cookie is now dead, so the actor re-authenticates
        // with the new factor on the next login — picking up `amr: ['totp']` for real.
        ($this->revokeAllSessions)($user->id());

        $this->audit->record(SecurityEventType::MfaEnrolled, $user->id());

        return $plaintextCodes;
    }
}
