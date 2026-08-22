<?php

declare(strict_types=1);

namespace App\Audit\Domain;

use App\Shared\Domain\SecurityEventType;
use Symfony\Component\Uid\Uuid;

/**
 * Note what is absent: no update, no delete. The table is append-only (ADR-0012), and the
 * interface says so — the database enforces it, but code should not even offer the option.
 */
interface SecurityEventRepository
{
    public function append(SecurityEvent $event): void;

    /** @return list<SecurityEvent> */
    public function findForActor(Uuid $actorId, int $limit = 50): array;

    /**
     * @param list<SecurityEventType> $types
     *
     * @return list<SecurityEvent>
     */
    public function findRecent(array $types = [], int $offset = 0, int $limit = 50): array;

    public function countAll(): int;
}
