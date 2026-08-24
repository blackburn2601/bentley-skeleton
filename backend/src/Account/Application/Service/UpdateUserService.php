<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Domain\AccountException;
use App\Account\Domain\User;
use App\Account\Domain\UserRepository;
use App\Audit\Application\AuditFacade;
use App\Shared\Domain\SecurityEventType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Applies an administrator's edit to one user's username.
 *
 * The username is the account's identity, so changing it is not a cosmetic edit. The status is
 * left alone — an Active account stays Active, so this cannot become a way to lock someone out
 * by editing their profile.
 */
final readonly class UpdateUserService
{
    public function __construct(
        private UserRepository $users,
        private AuditFacade $audit,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(Uuid $userId, string $username, Uuid $changedBy): User
    {
        $user = $this->users->findById($userId);

        if (!$user instanceof User) {
            throw AccountException::noSuchAccount();
        }

        $username = trim($username);

        if ($username === $user->username()) {
            return $user;
        }

        // citext enforces uniqueness case-insensitively; existsByUsername reads it the same way.
        if ($this->users->existsByUsername($username)) {
            throw AccountException::usernameAlreadyInUse($username);
        }

        $previous = $user->username();
        $user->changeUsername($username);
        $this->em->flush();

        $this->audit->record(SecurityEventType::AdminDataAccessed, $changedBy, [
            'action' => 'change_username',
            'subjectId' => $user->id()->toRfc4122(),
            'from' => $previous,
            'to' => $username,
        ]);

        return $user;
    }
}
