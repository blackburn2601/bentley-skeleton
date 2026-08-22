<?php

declare(strict_types=1);

namespace App\Account\Application;

use App\Account\Application\Service\RevokeAllSessionsService;
use App\Account\Domain\RefreshTokenRepository;
use App\Account\Domain\SingleUseTokenRepository;
use App\Account\Domain\User;
use App\Account\Domain\UserRepository;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Exposes the Account context to other contexts as a single narrow surface.
 *
 * Mostly read-only. The two writes below exist because GDPR erasure and data retention are
 * Audit's responsibility but act on Account's data — and the alternative is Audit reaching
 * into Account's repositories, which is the coupling INV-02 exists to prevent. Account decides
 * what "revoke every session" means; Audit only says when.
 */
final readonly class AccountFacade
{
    public function __construct(
        private UserRepository $users,
        private RefreshTokenRepository $refreshTokens,
        private SingleUseTokenRepository $singleUseTokens,
        private RevokeAllSessionsService $revokeAllSessions,
    ) {
    }

    public function findById(Uuid $userId): ?User
    {
        return $this->users->findById($userId);
    }

    public function emailOf(Uuid $userId): ?string
    {
        return $this->users->findById($userId)?->email();
    }

    public function exists(Uuid $userId): bool
    {
        return null !== $this->users->findById($userId);
    }

    /**
     * End every session belonging to a user.
     *
     * Called by GDPR erasure: an anonymised account whose tokens still work is not erased.
     *
     * @return int how many tokens were revoked
     */
    public function revokeAllSessions(Uuid $userId): int
    {
        return ($this->revokeAllSessions)($userId);
    }

    /**
     * Delete tokens whose retention period has ended.
     *
     * Expired tokens carry an IP address and a user agent, so removing them is a
     * data-minimisation obligation rather than housekeeping — which is why the Audit context
     * schedules it, and why the deletion itself happens here.
     *
     * @return array{refreshTokens: int, singleUseTokens: int}
     */
    public function purgeExpiredTokens(DateTimeImmutable $before): array
    {
        return [
            'refreshTokens' => $this->refreshTokens->deleteExpired($before),
            'singleUseTokens' => $this->singleUseTokens->deleteExpired($before),
        ];
    }
}
