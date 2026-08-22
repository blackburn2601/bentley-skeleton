<?php

declare(strict_types=1);

namespace App\Audit\Application\Service;

use App\Audit\Application\RequestContext;
use App\Audit\Domain\SecurityEvent;
use App\Audit\Domain\SecurityEventRepository;
use App\Shared\Domain\Clock;
use App\Shared\Domain\SecurityEventType;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Writes one immutable record of a security-relevant event.
 */
final readonly class RecordSecurityEventService
{
    public function __construct(
        private SecurityEventRepository $events,
        private RequestContextProvider $context,
        private Clock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $payload never credentials, tokens or their hashes
     */
    public function __invoke(
        SecurityEventType $type,
        ?Uuid $actorId = null,
        array $payload = [],
        ?RequestContext $context = null,
    ): void {
        $context ??= $this->context->current();

        $this->events->append(new SecurityEvent(
            type: $type,
            occurredAt: $this->clock->now(),
            actorId: $actorId,
            ipAddress: $context->ipAddress,
            userAgent: $context->userAgent,
            requestId: $context->requestId,
            payload: $payload,
        ));
    }
}
