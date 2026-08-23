<?php

declare(strict_types=1);

namespace App\Audit\Infrastructure\Repository;

use App\Audit\Domain\SecurityEvent;
use App\Audit\Domain\SecurityEventRepository;
use App\Shared\Domain\SecurityEventType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineSecurityEventRepository implements SecurityEventRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function append(SecurityEvent $event): void
    {
        $this->em->persist($event);

        // Flushed immediately, and deliberately.
        //
        // Persist alone only queues the row: it reaches the database when something else
        // happens to flush. Most security events are recorded on paths that do NOT write
        // anything else — a failed login, a rotated token, a detected reuse — so without this
        // the audit trail silently keeps only the events that happened to share a request with
        // an unrelated write. That is far worse than no audit trail, because it looks like one.
        //
        // Inside an open transaction this flushes within it, so an event and the change it
        // describes commit or roll back together. That is the behaviour we want: a
        // "family revoked" event that survived a rolled-back revocation would be a lie.
        $this->em->flush();
    }

    public function findForActor(Uuid $actorId, int $limit = 50): array
    {
        /** @var list<SecurityEvent> $events */
        $events = $this->em->createQueryBuilder()
            ->select('e')
            ->from(SecurityEvent::class, 'e')
            ->where('e.actorId = :actorId')
            ->setParameter('actorId', $actorId->toRfc4122())
            ->orderBy('e.occurredAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $events;
    }

    public function findRecent(array $types = [], int $offset = 0, int $limit = 50): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('e')
            ->from(SecurityEvent::class, 'e')
            ->orderBy('e.occurredAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        if ([] !== $types) {
            $qb->where('e.type IN (:types)')
                ->setParameter('types', array_map(static fn (SecurityEventType $t): string => $t->value, $types));
        }

        /** @var list<SecurityEvent> $events */
        $events = $qb->getQuery()->getResult();

        return $events;
    }

    public function countRecent(array $types = []): int
    {
        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(SecurityEvent::class, 'e');

        if ([] !== $types) {
            $qb->where('e.type IN (:types)')
                ->setParameter('types', array_map(static fn (SecurityEventType $t): string => $t->value, $types));
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function countAll(): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(SecurityEvent::class, 'e')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
