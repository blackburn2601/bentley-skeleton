<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Application\PasswordHasher;
use App\Account\Domain\TokenPurpose;
use App\Account\Domain\User;
use App\Account\Domain\UserRepository;
use App\Acl\Application\AclFacade;
use App\Audit\Application\AuditFacade;
use App\Shared\Domain\Clock;
use App\Shared\Domain\SecurityEventType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @responsibility Creates an unverified account for a new email address.
 */
final readonly class RegisterUserService
{
    public function __construct(
        private UserRepository $users,
        private AssertPasswordAcceptableService $assertAcceptable,
        private PasswordHasher $hasher,
        private IssueSingleUseTokenService $issueToken,
        private SendAccountEmailService $sendEmail,
        private AclFacade $acl,
        private AuditFacade $audit,
        private Clock $clock,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * Registering an already-registered address does NOT report that it exists.
     *
     * Otherwise the registration form becomes an account-enumeration oracle: submit an
     * address, learn whether that person has an account here. Instead the caller always sees
     * the same "check your email" response, and the address that is already registered gets a
     * "someone tried to register your address" email rather than a verification link.
     */
    public function __invoke(string $email, string $plainPassword): void
    {
        // Before the transaction: rejecting a weak password should not have opened one.
        ($this->assertAcceptable)($plainPassword, $email);

        $this->em->wrapInTransaction(function () use ($email, $plainPassword): void {
            $existing = $this->users->findByEmail($email);

            if (null !== $existing) {
                $this->sendEmail->duplicateRegistrationAttempt($existing);

                return;
            }

            $user = new User($email, $this->hasher->hash($plainPassword), $this->clock->now());
            $this->users->save($user);

            // Flush before issuing the token: the token has a foreign key to the user, and
            // the whole thing is inside one transaction so a failure leaves neither behind.
            $this->em->flush();

            // Every account gets the baseline role, or it cannot even read itself: the ACL
            // denies by default and a brand-new user holds no grants at all.
            $this->acl->assignDefaultRole($user->id());

            $token = ($this->issueToken)($user, TokenPurpose::VerifyEmail);
            $this->sendEmail->verification($user, $token);

            $this->audit->record(SecurityEventType::RegistrationCompleted, $user->id());
        });
    }
}
