<?php

declare(strict_types=1);

namespace App\Acl\Infrastructure\Repository;

use App\Acl\Domain\AclEntry;
use App\Acl\Domain\AclEntryRepository;
use App\Acl\Domain\SubjectSet;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineAclEntryRepository implements AclEntryRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function findCandidates(
        SubjectSet $subjects,
        string $resourceClass,
        array $resourceIds,
        string $permissionName,
    ): array {
        $qb = $this->em->createQueryBuilder()
            ->select('e', 'p')
            ->from(AclEntry::class, 'e')
            ->join('e.permission', 'p')
            ->where('e.resourceClass = :resourceClass')
            ->andWhere('p.name = :permission')
            ->setParameter('resourceClass', $resourceClass)
            ->setParameter('permission', $permissionName);

        // Class-level entries (resource_id IS NULL) are always in scope — they are the last
        // tier of every check, whether or not a specific object was named.
        if ([] === $resourceIds) {
            $qb->andWhere('e.resourceId IS NULL');
        } else {
            $qb->andWhere('e.resourceId IN (:resourceIds) OR e.resourceId IS NULL')
                ->setParameter('resourceIds', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $resourceIds));
        }

        $this->constrainToSubjects($qb, $subjects);

        /** @var list<AclEntry> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    public function findClassLevelResourceClasses(SubjectSet $subjects, string $permissionName): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('DISTINCT e.resourceClass')
            ->from(AclEntry::class, 'e')
            ->join('e.permission', 'p')
            ->where('p.name = :permission')
            ->andWhere('e.resourceId IS NULL')
            ->setParameter('permission', $permissionName);

        $this->constrainToSubjects($qb, $subjects);

        /** @var list<array{resourceClass: string}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        /** @var list<class-string> $classes */
        $classes = array_values(array_filter(
            array_column($rows, 'resourceClass'),
            class_exists(...),
        ));

        return $classes;
    }

    public function save(AclEntry $entry): void
    {
        $this->em->persist($entry);
    }

    public function remove(AclEntry $entry): void
    {
        $this->em->remove($entry);
    }

    public function findById(Uuid $id): ?AclEntry
    {
        return $this->em->find(AclEntry::class, $id);
    }

    public function findForResource(string $resourceClass, ?Uuid $resourceId): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('e', 'p')
            ->from(AclEntry::class, 'e')
            ->join('e.permission', 'p')
            ->where('e.resourceClass = :resourceClass')
            ->setParameter('resourceClass', $resourceClass)
            ->orderBy('e.createdAt', 'DESC');

        if (!$resourceId instanceof Uuid) {
            $qb->andWhere('e.resourceId IS NULL');
        } else {
            $qb->andWhere('e.resourceId = :resourceId')->setParameter('resourceId', $resourceId->toRfc4122());
        }

        /** @var list<AclEntry> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Restrict to entries whose (subject_type, subject_id) is one this caller actually is.
     *
     * Typed pairs rather than a bare id list: subject ids are UUIDs drawn from three
     * different tables, and matching on the id alone would let a group grant apply to a user
     * that happened to share the value. Vanishingly unlikely, and free to rule out.
     */
    private function constrainToSubjects(QueryBuilder $qb, SubjectSet $subjects): void
    {
        $clauses = [];

        foreach ($subjects->pairs() as $index => [$type, $id]) {
            $clauses[] = \sprintf('(e.subjectType = :st%d AND e.subjectId = :si%d)', $index, $index);
            $qb->setParameter('st'.$index, $type->value);
            $qb->setParameter('si'.$index, $id->toRfc4122());
        }

        $qb->andWhere('('.implode(' OR ', $clauses).')');
    }
}
