<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Application\PasswordHasher;
use App\Account\Domain\AccountException;
use App\Account\Domain\TokenPurpose;
use App\Account\Domain\User;
use App\Account\Domain\UserRepository;
use App\Acl\Application\AclFacade;
use App\Audit\Application\AuditFacade;
use App\Shared\Domain\Clock;
use App\Shared\Domain\SecretGenerator;
use App\Shared\Domain\SecurityEventType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Creates a user account on an administrator's behalf.
 *
 * Deliberately not RegisterUserService. That one exists to be safe for anonymous callers, so
 * it never reveals whether an address is already registered — it silently emails the existing
 * holder instead. That property is exactly wrong here: an administrator filling in a form
 * needs to be told the address is taken, or they sit waiting for an account that will never
 * appear.
 *
 * **No administrator ever learns the password.** The account is created with a random secret
 * nobody keeps, and the new user sets their own through the ordinary reset flow. The email
 * that carries the link also proves they control the address, which is what makes it safe to
 * mark it verified without a separate verification round trip.
 */
final readonly class CreateUserService
{
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $hasher,
        private IssueSingleUseTokenService $issueToken,
        private SendAccountEmailService $email,
        private AclFacade $acl,
        private AuditFacade $audit,
        private SecretGenerator $secrets,
        private Clock $clock,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(string $email, Uuid $createdBy): User
    {
        $email = mb_strtolower(trim($email));

        if ($this->users->existsByEmail($email)) {
            throw AccountException::emailAlreadyInUse($email);
        }

        $now = $this->clock->now();

        // A password that is never shown, never stored in plaintext, and never needed: the
        // holder replaces it before they can sign in.
        $user = new User($email, $this->hasher->hash($this->secrets->generate()), $now);
        $user->verifyEmail($now);

        $this->users->save($user);
        $this->em->flush();

        $this->acl->assignDefaultRole($user->id());

        $this->email->passwordReset($user, ($this->issueToken)($user, TokenPurpose::ResetPassword));

        $this->audit->record(SecurityEventType::RegistrationCompleted, $createdBy, [
            'subjectId' => $user->id()->toRfc4122(),
            'createdByAdministrator' => true,
        ]);

        return $user;
    }
}
