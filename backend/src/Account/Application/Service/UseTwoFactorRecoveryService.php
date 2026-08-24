<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Application\IssuedSession;
use App\Account\Domain\AccountException;
use App\Account\Domain\MfaRecoveryCode;
use App\Account\Domain\MfaRecoveryCodeRepository;
use App\Account\Domain\User;
use App\Account\Domain\UserRepository;
use App\Audit\Application\AuditFacade;
use App\Shared\Domain\Clock;
use App\Shared\Domain\SecurityEventType;
use App\Shared\Domain\TokenHash;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Burns one recovery code in lieu of the TOTP second factor.
 */
final readonly class UseTwoFactorRecoveryService
{
    public function __construct(
        private UserRepository $users,
        private MfaRecoveryCodeRepository $recoveryCodes,
        private CompleteSessionService $completeSession,
        private AuditFacade $audit,
        private Clock $clock,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(Uuid $userId, string $code, ?string $ipAddress, ?string $userAgent): IssuedSession
    {
        $user = $this->users->findById($userId);

        if (!$user instanceof User) {
            throw AccountException::invalidRecoveryCode();
        }

        // Strip everything that is not a digit, so a caller who copies "123-456-7890" is not
        // punished for the formatting the enrollment screen chose. The hash is taken of the
        // normalized form, so lookup is format-independent.
        $normalized = preg_replace('/\D/', '', $code) ?? '';

        $recovery = '' !== $normalized
            ? $this->recoveryCodes->findForUser($user->id(), TokenHash::of($normalized)->value)
            : null;

        // One response for "no such code", "already used", and "belongs to another user":
        // revealing which would turn the recovery endpoint into a guessing oracle. The lookup
        // is scoped to this user, so another user's code is simply absent here.
        if (!$recovery instanceof MfaRecoveryCode || $recovery->isUsed()) {
            throw AccountException::invalidRecoveryCode();
        }

        $recovery->markUsed($this->clock->now());
        $this->em->flush();

        // High severity: a recovery code is the fallback for a lost device, so spending one
        // means the caller could not authenticate normally — exactly what a review pages on.
        $this->audit->record(SecurityEventType::MfaRecoveryUsed, $user->id());

        // A recovery code proves factor ownership, so the session is minted with the same
        // `amr: ['totp']` a successful TOTP verify would carry.
        return ($this->completeSession)($user, ['totp'], $ipAddress, $userAgent);
    }
}
