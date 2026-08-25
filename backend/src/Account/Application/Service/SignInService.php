<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Application\IssuedSession;
use App\Account\Application\TwoFactorChallenge;
use App\Account\Domain\AccountException;
use App\Audit\Application\AuditFacade;
use App\Shared\Domain\SecurityEventType;

/**
 * @responsibility Turns valid credentials into an authenticated session or an MFA challenge.
 */
final readonly class SignInService
{
    public function __construct(
        private AuthenticateUserService $authenticate,
        private CompleteSessionService $completeSession,
        private AuditFacade $audit,
        private IssueTwoFactorChallengeService $issueChallenge,
    ) {
    }

    public function __invoke(string $username, string $password, ?string $ipAddress, ?string $userAgent): IssuedSession|TwoFactorChallenge
    {
        $user = ($this->authenticate)($username, $password);

        // MFA branches AFTER the password check, so the anti-enumeration responses of
        // AuthenticateUserService stay byte-identical for wrong-password vs. unknown-user.
        // The password being correct is what makes revealing "MFA applies" safe here.
        if ($user->mfaApplies()) {
            if (null === $user->totpSecretEncrypted()) {
                // Required by an admin but never enrolled. The user cannot enroll at the
                // login prompt (that would be a phishing primitive), so this is a dead end
                // they hand to an administrator rather than a step they complete.
                throw AccountException::mfaRequiredNotEnrolled();
            }

            return ($this->issueChallenge)($user);
        }

        $session = ($this->completeSession)($user, [], $ipAddress, $userAgent);

        $this->audit->record(SecurityEventType::LoginSucceeded, $user->id());

        return $session;
    }
}
