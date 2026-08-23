<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Domain\AccountException;
use App\Account\Domain\UserRepository;
use App\Audit\Application\AuditFacade;
use App\Shared\Domain\SecurityEventType;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Ends every session belonging to one user at an administrator's request.
 *
 * The answer to "their laptop was stolen" that does not also lock them out of their account.
 * Suspension does this too, but as a side effect of something much heavier.
 */
final readonly class RevokeUserSessionsService
{
    public function __construct(
        private UserRepository $users,
        private RevokeAllSessionsService $revokeAll,
        private AuditFacade $audit,
    ) {
    }

    public function __invoke(Uuid $userId, Uuid $revokedBy): int
    {
        if (null === $this->users->findById($userId)) {
            throw AccountException::noSuchAccount();
        }

        $revoked = ($this->revokeAll)($userId);

        $this->audit->record(SecurityEventType::AllSessionsRevoked, $revokedBy, [
            'subjectId' => $userId->toRfc4122(),
            'sessionsRevoked' => $revoked,
            'byAdministrator' => true,
        ]);

        return $revoked;
    }
}
