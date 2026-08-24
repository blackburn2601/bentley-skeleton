<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Application\CreatedUser;
use App\Account\Application\PasswordHasher;
use App\Account\Domain\AccountException;
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
 * @responsibility Creates a user account with a system-generated temporary password.
 *
 * Accounts are administrator-issued only (ADR-0024): there is no self-registration, and the
 * temporary password is returned once so the admin can hand it over out-of-band. The plaintext
 * never persists and never logs — it leaves this service in a {@see CreatedUser} container and
 * the controller surfaces it in the response exactly once.
 */
final readonly class CreateUserService
{
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $hasher,
        private AclFacade $acl,
        private AuditFacade $audit,
        private SecretGenerator $secrets,
        private Clock $clock,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(string $username, Uuid $createdBy): CreatedUser
    {
        $username = trim($username);

        if ($this->users->existsByUsername($username)) {
            throw AccountException::usernameAlreadyInUse($username);
        }

        // A password the administrator sees once and hands over; the holder may change it later.
        $temporaryPassword = $this->secrets->generateTemporaryPassword();

        $now = $this->clock->now();
        $user = new User($username, $this->hasher->hash($temporaryPassword), $now);

        $this->users->save($user);
        $this->em->flush();

        $this->acl->assignDefaultRole($user->id());

        $this->audit->record(SecurityEventType::UserCreated, $createdBy, [
            'subjectId' => $user->id()->toRfc4122(),
            'createdByAdministrator' => true,
        ]);

        return new CreatedUser(
            $user->id()->toRfc4122(),
            $user->username(),
            $user->status()->value,
            $temporaryPassword,
        );
    }
}
