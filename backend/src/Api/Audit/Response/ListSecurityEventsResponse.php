<?php

declare(strict_types=1);

namespace App\Api\Audit\Response;

/**
 * One page of the security event log.
 *
 * `payload` and `userAgent` are deliberately absent from the list: payloads are free-form
 * JSONB that can carry personal data, and a list view is the wrong place to spray it across
 * the screen. They belong on a detail view, behind a deliberate click.
 */
final readonly class ListSecurityEventsResponse
{
    /**
     * @param list<array{id: string, type: string, occurredAt: string, actorId: string|null, ipAddress: string|null, requestId: string|null, highSeverity: bool}> $items
     */
    private function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
    ) {
    }

    /**
     * @param list<array{id: string, type: string, occurredAt: string, actorId: string|null, ipAddress: string|null, requestId: string|null, highSeverity: bool}> $events
     */
    public static function from(array $events, int $page, int $perPage, int $total): self
    {
        return new self($events, $page, $perPage, $total);
    }
}
