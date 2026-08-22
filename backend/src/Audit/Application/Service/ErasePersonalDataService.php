<?php

declare(strict_types=1);

namespace App\Audit\Application\Service;

use App\Account\Application\AccountFacade;
use App\Shared\Domain\Clock;
use App\Shared\Domain\SecurityEventType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Anonymises a person's account in response to an erasure request.
 */
final readonly class ErasePersonalDataService
{
    public function __construct(
        private AccountFacade $accounts,
        private RecordSecurityEventService $recordEvent,
        private Clock $clock,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * Anonymise rather than delete, and the difference is the point.
     *
     * GDPR Article 17 is not absolute: Article 17(3) preserves processing necessary for legal
     * claims and compliance obligations, and an audit trail that can be erased by its subject
     * is not an audit trail. So:
     *
     *   - The identifying fields on the account are replaced with a placeholder and the
     *     credentials are destroyed. The person is no longer identifiable from it.
     *   - `security_event` rows are KEPT, and keeping them is what the retention exception in
     *     docs/SECURITY.md documents. They are already pseudonymous — an actor id, an IP and
     *     an event type — and the id no longer resolves to a person.
     *
     * Deleting the row outright would also cascade the refresh tokens and role assignments
     * away, silently destroying the evidence of any incident involving that account.
     *
     * @return array{erased: bool, sessionsRevoked: int}
     */
    public function __invoke(Uuid $userId): array
    {
        $user = $this->accounts->findById($userId);

        if (null === $user) {
            return ['erased' => false, 'sessionsRevoked' => 0];
        }

        $now = $this->clock->now();
        $revoked = 0;

        // Recorded BEFORE the erasure: afterwards there is deliberately no way to tie the
        // request back to who made it.
        ($this->recordEvent)(SecurityEventType::GdprErasureRequested, $userId);

        $this->em->wrapInTransaction(function () use ($user, $userId, $now, &$revoked): void {
            // Through the facade: revoking sessions is Account's business (INV-02).
            $revoked = $this->accounts->revokeAllSessions($userId);

            $user->anonymise(
                \sprintf('erased-%s@invalid.local', $userId->toRfc4122()),
                $now,
            );

            $this->em->flush();
        });

        return ['erased' => true, 'sessionsRevoked' => $revoked];
    }
}
