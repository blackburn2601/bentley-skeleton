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
    public function findRecent(array $types = [], ?string $query = null, int $offset = 0, int $limit = 50): array;

    /**
     * The total findRecent() would return unpaged, under the same type filter.
     *
     * countAll() cannot serve a filtered list: it ignores the filter, so a pager built on it
     * offers pages of a result set that does not exist.
     *
     * @param list<SecurityEventType> $types
     */
    public function countRecent(array $types = [], ?string $query = null): int;

    public function countAll(): int;
}
