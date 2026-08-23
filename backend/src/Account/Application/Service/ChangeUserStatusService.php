<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Domain\AccountException;
use App\Account\Domain\User;
use App\Account\Domain\UserRepository;
use App\Account\Domain\UserStatus;
use App\Audit\Application\AuditFacade;
use App\Shared\Domain\SecurityEventType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Moves a user account to a new administrative status.
 *
 * One service rather than separate suspend/reinstate ones: "suspends or reinstates" is a
 * single topic with two directions, and splitting it would duplicate the guards below.
 *
 * Suspension revokes every session as well as blocking the next sign-in. Leaving them open
 * would mean a suspended user keeps working until their access token expires, which is up to
 * ten minutes of access somebody just decided to withdraw.
 */
final readonly class ChangeUserStatusService
{
    public function __construct(
        private UserRepository $users,
        private RevokeAllSessionsService $revokeSessions,
        private AuditFacade $audit,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(Uuid $userId, UserStatus $status, Uuid $changedBy): User
    {
        $user = $this->users->findById($userId);

        if (!$user instanceof User) {
            throw AccountException::noSuchAccount();
        }

        if ($userId->equals($changedBy)) {
            throw AccountException::cannotChangeOwnStatus();
        }

        if (UserStatus::Anonymised === $user->status()) {
            throw AccountException::accountIsAnonymised();
        }

        match ($status) {
            UserStatus::Suspended => $user->suspend(),
            UserStatus::Active => $user->reinstate(),
            // Neither is an administrative destination: PendingVerification is where an
            // account starts, and Anonymised is what erasure produces.
            default => throw AccountException::statusNotSettable($status->value),
        };

        $this->em->flush();

        $revoked = UserStatus::Suspended === $status ? ($this->revokeSessions)($userId) : 0;

        $this->audit->record(SecurityEventType::AdminDataAccessed, $changedBy, [
            'action' => 'change_status',
            'subjectId' => $userId->toRfc4122(),
            'status' => $status->value,
            'sessionsRevoked' => $revoked,
        ]);

        return $user;
    }
}
