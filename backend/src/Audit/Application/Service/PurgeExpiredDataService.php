<?php

declare(strict_types=1);

namespace App\Audit\Application\Service;

use App\Account\Application\AccountFacade;
use App\Shared\Domain\Clock;
use DateTimeImmutable;

/**
 * @responsibility Removes data whose retention period has ended.
 */
final readonly class PurgeExpiredDataService
{
    public function __construct(
        private AccountFacade $accounts,
        private Clock $clock,
    ) {
    }

    /**
     * Deletes expired TOKENS only.
     *
     * Note what is absent: `security_event`. Those rows are append-only and the application's
     * database role cannot delete them (ADR-0012) — deliberately, because a retention job with
     * DELETE on the audit table is also the tool an attacker would reach for. Audit retention
     * runs separately, as the owner role, on a schedule an operator controls
     * (docs/OPERATIONS.md).
     *
     * Expired tokens carry an IP address and a user agent, so purging them is a genuine
     * data-minimisation obligation and not just housekeeping.
     *
     * @return array{refreshTokens: int}
     */
    public function __invoke(?DateTimeImmutable $before = null): array
    {
        // A grace period, not "now": a token that expired thirty seconds ago is still useful
        // when someone reports "it logged me out" and support wants to see the session.
        $cutoff = $before ?? $this->clock->now()->modify('-30 days');

        // Through the facade: the tokens belong to Account (INV-02).
        return $this->accounts->purgeExpiredTokens($cutoff);
    }
}
