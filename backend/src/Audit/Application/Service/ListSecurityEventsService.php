<?php

declare(strict_types=1);

namespace App\Audit\Application\Service;

use App\Audit\Domain\SecurityEvent;
use App\Audit\Domain\SecurityEventRepository;
use App\Shared\Domain\SecurityEventType;
use DateTimeInterface;

/**
 * @responsibility Lists the recorded security events matching a filter.
 *
 * Deliberately not ACL-filtered. A SecurityEvent is not an ACL resource — per-object
 * permissions on an append-only log would mean an audit trail that shows different histories to
 * different auditors, which is the opposite of what an audit trail is for. `audit.read` is a
 * class-level question and the whole answer.
 */
final readonly class ListSecurityEventsService
{
    public function __construct(private SecurityEventRepository $events)
    {
    }

    /**
     * @param list<SecurityEventType> $types
     *
     * @return array{items: list<array{id: string, type: string, occurredAt: string, actorId: string|null, ipAddress: string|null, requestId: string|null, highSeverity: bool}>, total: int}
     */
    public function __invoke(array $types, int $offset, int $limit): array
    {
        $items = array_map(static fn (SecurityEvent $event): array => [
            'id' => $event->id()->toRfc4122(),
            'type' => $event->type()->value,
            'occurredAt' => $event->occurredAt()->format(DateTimeInterface::ATOM),
            'actorId' => $event->actorId()?->toRfc4122(),
            'ipAddress' => $event->ipAddress(),
            'requestId' => $event->requestId(),
            'highSeverity' => $event->type()->isHighSeverity(),
        ], $this->events->findRecent($types, $offset, $limit));

        // countRecent, not countAll: a total computed without the type filter would page the
        // caller through positions that do not exist in the filtered result.
        return ['items' => $items, 'total' => $this->events->countRecent($types)];
    }
}
