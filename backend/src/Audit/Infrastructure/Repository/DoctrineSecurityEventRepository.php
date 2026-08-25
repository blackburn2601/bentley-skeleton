<?php

declare(strict_types=1);

namespace App\Audit\Infrastructure\Repository;

use App\Audit\Domain\SecurityEvent;
use App\Audit\Domain\SecurityEventRepository;
use App\Shared\Domain\SecurityEventType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
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

    public function findRecent(array $types = [], ?string $query = null, int $offset = 0, int $limit = 50): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('e')
            ->from(SecurityEvent::class, 'e')
            ->orderBy('e.occurredAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $this->applyFilter($qb, $types, $query);

        /** @var list<SecurityEvent> $events */
        $events = $qb->getQuery()->getResult();

        return $events;
    }

    public function countRecent(array $types = [], ?string $query = null): int
    {
        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(SecurityEvent::class, 'e');

        $this->applyFilter($qb, $types, $query);

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

    /**
     * The shared predicate for findRecent() and countRecent().
     *
     * Extracted because the two previously each rebuilt the type clause by hand, and a search
     * clause added twice would drift the same way. Both filters are ANDed; the type list is a
     * precise programmatic filter, the query is a fuzzy substring one, and a caller may use both.
     *
     * @param list<SecurityEventType> $types
     */
    private function applyFilter(QueryBuilder $qb, array $types, ?string $query): void
    {
        if ([] !== $types) {
            $qb->andWhere('e.type IN (:types)')
                ->setParameter('types', array_map(static fn (SecurityEventType $t): string => $t->value, $types));
        }

        if (null !== $query && '' !== $query) {
            // Searched across everything an operator would hold a fragment of: the event type
            // (an enum wire value like login_succeeded, so "login" finds both login events), the
            // actor id, the IP and the request id.
            //
            // Parenthesised explicitly. Doctrine does bracket a part containing OR (DDC-1237),
            // but the type filter above is ANDed on, and an OR that escaped its brackets there
            // would widen the count past the filtered set. Not a thing to leave to a regex.
            //
            // The id side uses TEXT() (see TextCastFunction): actorId is a `uuid` column, which
            // cannot be pattern-matched at all without a cast. TEXT() renders the canonical
            // lowercase form, so both ends of the id comparison are lowered; the other columns
            // are plain strings lowered on both ends for a predictable case-insensitive match.
            $qb->andWhere(
                '(LOWER(e.type) LIKE :query OR LOWER(e.ipAddress) LIKE :query '
                .'OR LOWER(e.requestId) LIKE :query OR TEXT(e.actorId) LIKE :query)',
            )->setParameter('query', '%'.strtolower($query).'%');
        }
    }
}
