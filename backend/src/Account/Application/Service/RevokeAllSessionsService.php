<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Domain\RefreshTokenRepository;
use App\Audit\Application\AuditFacade;
use App\Shared\Domain\Clock;
use App\Shared\Domain\SecurityEventType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Revokes every refresh-token family belonging to one user.
 */
final readonly class RevokeAllSessionsService
{
    public function __construct(
        private RefreshTokenRepository $tokens,
        private AuditFacade $audit,
        private Clock $clock,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @return int how many tokens were revoked
     */
    public function __invoke(Uuid $userId): int
    {
        $revoked = $this->tokens->revokeAllForUser($userId, $this->clock->now());
        $this->em->flush();

        $this->audit->record(SecurityEventType::AllSessionsRevoked, $userId, [
            'tokensRevoked' => $revoked,
        ]);

        return $revoked;
    }
}
