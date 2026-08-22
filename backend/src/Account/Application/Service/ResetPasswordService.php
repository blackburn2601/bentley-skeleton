<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Application\PasswordHasher;
use App\Account\Domain\AccountException;
use App\Account\Domain\RefreshTokenRepository;
use App\Account\Domain\SingleUseTokenRepository;
use App\Account\Domain\TokenPurpose;
use App\Audit\Application\AuditFacade;
use App\Shared\Domain\Clock;
use App\Shared\Domain\SecurityEventType;
use App\Shared\Domain\TokenHash;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @responsibility Sets a new password from a valid reset token.
 */
final readonly class ResetPasswordService
{
    public function __construct(
        private SingleUseTokenRepository $tokens,
        private RefreshTokenRepository $refreshTokens,
        private AssertPasswordAcceptableService $assertAcceptable,
        private PasswordHasher $hasher,
        private SendAccountEmailService $sendEmail,
        private AuditFacade $audit,
        private Clock $clock,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(string $plaintextToken, string $newPassword): void
    {
        $now = $this->clock->now();
        $token = $this->tokens->findByHash(TokenHash::of($plaintextToken)->value);

        if (null === $token || !$token->isUsableAt($now, TokenPurpose::ResetPassword)) {
            throw AccountException::invalidToken();
        }

        $user = $token->user();

        ($this->assertAcceptable)($newPassword, $user->email());

        $revoked = 0;

        $this->em->wrapInTransaction(function () use ($token, $user, $newPassword, $now, &$revoked): void {
            $token->consume($now);
            $user->changePassword($this->hasher->hash($newPassword), $now);

            // Every existing session dies. A reset is the standard response to "someone else
            // has my password", and leaving their sessions alive would defeat the entire
            // point of resetting it.
            $revoked = $this->refreshTokens->revokeAllForUser($user->id(), $now);

            // Locked out by failed attempts? Proving control of the mailbox clears that.
            $user->recordSuccessfulLogin();

            $this->em->flush();
        });

        $this->sendEmail->passwordChanged($user);

        $this->audit->record(SecurityEventType::PasswordChanged, $user->id(), [
            'via' => 'reset_token',
            'sessionsRevoked' => $revoked,
        ]);
    }
}
