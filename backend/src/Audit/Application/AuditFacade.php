<?php

declare(strict_types=1);

namespace App\Audit\Application;

use App\Audit\Application\Service\RecordSecurityEventService;
use App\Shared\Domain\SecurityEventType;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Exposes the Audit context to other contexts as a single narrow surface.
 *
 * Every context that needs to record something security-relevant goes through here, so
 * `grep -rn AuditFacade src/` answers "what do we audit, and from where?" — which is the
 * first question any security review asks.
 *
 * There is deliberately no read method for other contexts. Reading the audit trail is an
 * administrative action behind `audit.read`, served by the Audit context's own endpoints.
 */
final readonly class AuditFacade
{
    public function __construct(private RecordSecurityEventService $record)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function record(SecurityEventType $type, ?Uuid $actorId = null, array $payload = []): void
    {
        ($this->record)($type, $actorId, $payload);
    }
}
