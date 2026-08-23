<?php

declare(strict_types=1);

namespace App\Audit\Application\Service;

use App\Account\Application\AccountFacade;
use App\Audit\Domain\EraseRefused;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Anonymises another person's account at an administrator's request.
 *
 * Separate from ErasePersonalDataService, which it delegates to, because the guard is
 * different rather than the erasure: a person erasing their own account is exercising a right,
 * an administrator erasing someone else's is an irreversible act performed on a third party.
 * The self-erasure endpoint has no reason to carry that check, and this one cannot do without
 * it — an administrator who erases themselves cannot undo it, because the account that held
 * the permission no longer exists.
 */
final readonly class EraseUserService
{
    public function __construct(
        private ErasePersonalDataService $erase,
        private AccountFacade $accounts,
    ) {
    }

    /**
     * @return array{erased: bool, sessionsRevoked: int}
     */
    public function __invoke(Uuid $userId, Uuid $erasedBy): array
    {
        if ($userId->equals($erasedBy)) {
            throw EraseRefused::ownAccount();
        }

        if (!$this->accounts->exists($userId)) {
            throw EraseRefused::noSuchAccount();
        }

        return ($this->erase)($userId);
    }
}
