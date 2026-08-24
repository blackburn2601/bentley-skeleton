<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Application\IssuedSession;
use App\Account\Application\Totp;
use App\Account\Domain\AccountException;
use App\Account\Domain\User;
use App\Account\Domain\UserRepository;
use App\Audit\Application\AuditFacade;
use App\Shared\Domain\SecretEncryptor;
use App\Shared\Domain\SecurityEventType;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Verifies a TOTP second factor to complete the authenticated session.
 */
final readonly class VerifyTwoFactorService
{
    public function __construct(
        private UserRepository $users,
        private Totp $totp,
        private SecretEncryptor $encryptor,
        private CompleteSessionService $completeSession,
        private AuditFacade $audit,
    ) {
    }

    public function __invoke(Uuid $userId, string $code, ?string $ipAddress, ?string $userAgent): IssuedSession
    {
        $user = $this->users->findById($userId);

        // Anti-enumeration: every failure path — no such user, no enrolled secret, a wrong code —
        // throws the identical 401, so a verify attempt cannot reveal which one failed. The
        // username is never read from the body; the user is identified solely by the signed
        // `sub` of the challenge token.
        if (!$user instanceof User) {
            throw AccountException::invalidTwoFactorCode();
        }

        $secretEncrypted = $user->totpSecretEncrypted();

        // The empty-string half guards against a corrupted row and narrows the type for the
        // decryptor, which requires a non-empty ciphertext.
        if (null === $secretEncrypted || '' === $secretEncrypted) {
            throw AccountException::invalidTwoFactorCode();
        }

        if (!$this->totp->verify($this->encryptor->decrypt($secretEncrypted), $code)) {
            $this->audit->record(SecurityEventType::MfaChallengeFailed, $user->id());

            throw AccountException::invalidTwoFactorCode();
        }

        $this->audit->record(SecurityEventType::MfaVerified, $user->id());

        // `amr: ['totp']` is carried on the access token and the refresh row, so refresh rotates
        // it without re-challenging (ADR-0026).
        return ($this->completeSession)($user, ['totp'], $ipAddress, $userAgent);
    }
}
