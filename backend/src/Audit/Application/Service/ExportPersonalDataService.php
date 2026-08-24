<?php

declare(strict_types=1);

namespace App\Audit\Application\Service;

use App\Account\Application\AccountFacade;
use App\Audit\Domain\SecurityEvent;
use App\Audit\Domain\SecurityEventRepository;
use App\Shared\Domain\SecurityEventType;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Assembles everything this system holds about one person.
 */
final readonly class ExportPersonalDataService
{
    public function __construct(
        private AccountFacade $accounts,
        private SecurityEventRepository $events,
        private RecordSecurityEventService $recordEvent,
    ) {
    }

    /**
     * A GDPR Article 15 export.
     *
     * Deliberately assembled from named fields rather than by serializing entities. An export
     * that walks the object graph publishes whatever the schema happens to contain today —
     * including the password hash, which is precisely the thing that must never leave. Listing
     * the fields makes each one a decision.
     *
     * The request is itself audited: someone asking for a copy of their data is a
     * security-relevant event, and so is somebody else asking on their behalf.
     *
     * @return array{subject: array<string, mixed>, securityEvents: list<array<string, mixed>>, generatedAt: string}
     */
    public function __invoke(Uuid $userId): array
    {
        $user = $this->accounts->findById($userId);

        if (null === $user) {
            return ['subject' => [], 'securityEvents' => [], 'generatedAt' => date(\DATE_ATOM)];
        }

        ($this->recordEvent)(SecurityEventType::GdprExportRequested, $userId);

        return [
            'subject' => [
                'id' => $user->id()->toRfc4122(),
                'username' => $user->username(),
                'status' => $user->status()->value,
                'passwordChangedAt' => $user->passwordChangedAt()->format(\DATE_ATOM),
                // Absent on purpose: passwordHash.
            ],
            'securityEvents' => array_map(
                static fn (SecurityEvent $event): array => [
                    'type' => $event->type()->value,
                    'occurredAt' => $event->occurredAt()->format(\DATE_ATOM),
                    'ipAddress' => $event->ipAddress(),
                    'userAgent' => $event->userAgent(),
                    'detail' => $event->payload(),
                ],
                $this->events->findForActor($userId, 1000),
            ),
            'generatedAt' => date(\DATE_ATOM),
        ];
    }
}
