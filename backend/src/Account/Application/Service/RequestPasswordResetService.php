<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Domain\TokenPurpose;
use App\Account\Domain\UserRepository;
use App\Audit\Application\AuditFacade;
use App\Shared\Domain\SecurityEventType;

/**
 * @responsibility Emails a password-reset link to an address that has an account.
 */
final readonly class RequestPasswordResetService
{
    public function __construct(
        private UserRepository $users,
        private IssueSingleUseTokenService $issueToken,
        private SendAccountEmailService $sendEmail,
        private AuditFacade $audit,
    ) {
    }

    /**
     * Returns nothing, and reports nothing, whether or not the address exists.
     *
     * "No account with that email" would turn this endpoint into a membership oracle for any
     * address an attacker cares to try — and this one needs no credentials at all. The caller
     * always sees the same response; only a real account receives a link.
     */
    public function __invoke(string $email): void
    {
        $user = $this->users->findByEmail($email);

        if (null === $user || !$user->status()->canAuthenticate()) {
            return;
        }

        $token = ($this->issueToken)($user, TokenPurpose::ResetPassword);
        $this->sendEmail->passwordReset($user, $token);

        $this->audit->record(SecurityEventType::PasswordResetRequested, $user->id());
    }
}
