<?php

declare(strict_types=1);

namespace App\Audit\Domain;

use App\Shared\Domain\SecurityEventType;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One append-only record of something security-relevant (ADR-0012).
 *
 * There are no setters and no mutating methods, because the row is never meant to change
 * after it is written. That is enforced properly at the database — the application's role
 * holds INSERT and nothing else on this table — but the class says the same thing, so the
 * intent is visible without reading a migration.
 *
 * `actorId` is a bare UUID rather than a User association on purpose. Audit must be able to
 * record an event about a user who is later erased under GDPR, and a foreign key with a
 * cascade would delete the evidence along with the account.
 */
#[ORM\Entity]
#[ORM\Table(name: 'security_event')]
#[ORM\Index(name: 'idx_security_event_actor', columns: ['actor_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_security_event_type', columns: ['type', 'occurred_at'])]
#[ORM\Index(name: 'idx_security_event_occurred', columns: ['occurred_at'])]
class SecurityEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    public function __construct(
        #[ORM\Column(type: 'string', length: 64, enumType: SecurityEventType::class)]
        private SecurityEventType $type,
        #[ORM\Column(type: 'datetime_immutable')]
        private DateTimeImmutable $occurredAt,
        /** Who did it. Null for events with no authenticated actor, such as a failed login. */
        #[ORM\Column(type: 'uuid', nullable: true)]
        private ?Uuid $actorId = null,
        #[ORM\Column(type: 'string', length: 45, nullable: true)]
        private ?string $ipAddress = null,
        #[ORM\Column(type: 'string', length: 255, nullable: true)]
        private ?string $userAgent = null,
        /** Ties the event to the log lines and the problem+json body the user saw. */
        #[ORM\Column(type: 'string', length: 64, nullable: true)]
        private ?string $requestId = null,
        /**
         * Event-specific detail as JSONB.
         *
         * Never put credentials, tokens or their hashes in here. An audit log is read by more
         * people than the user table is.
         *
         * @var array<string, mixed>
         */
        #[ORM\Column(type: 'json', options: ['jsonb' => true])]
        private array $payload = [],
    ) {
        $this->id = Uuid::v7();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function type(): SecurityEventType
    {
        return $this->type;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function actorId(): ?Uuid
    {
        return $this->actorId;
    }

    public function ipAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function userAgent(): ?string
    {
        return $this->userAgent;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }
}
