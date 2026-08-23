<?php

declare(strict_types=1);

namespace App\Tests\Unit\Acl\Double;

use App\Acl\Domain\AclEntry;
use App\Acl\Domain\AclEntryRepository;
use App\Acl\Domain\AclSubjectType;
use App\Acl\Domain\SubjectSet;
use Symfony\Component\Uid\Uuid;

/**
 * In-memory entries, so the decision matrix runs without a database.
 *
 * The matching logic here is deliberately naive — it filters, it does not decide. All the
 * tier precedence lives in PermissionResolver, which is what the matrix is testing. A double
 * that reimplemented the algorithm would test itself.
 */
final class InMemoryAclEntryRepository implements AclEntryRepository
{
    /** @var list<AclEntry> */
    private array $entries = [];

    public function add(AclEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    public function findCandidates(
        SubjectSet $subjects,
        string $resourceClass,
        array $resourceIds,
        string $permissionName,
    ): array {
        $wanted = [];
        foreach ($subjects->pairs() as [$type, $id]) {
            $wanted[$type->value.':'.$id->toRfc4122()] = true;
        }

        $ids = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $resourceIds);

        return array_values(array_filter($this->entries, static function (AclEntry $e) use ($wanted, $resourceClass, $ids, $permissionName): bool {
            if ($e->resourceClass() !== $resourceClass || $e->permission()->name() !== $permissionName) {
                return false;
            }

            if (!isset($wanted[$e->subjectType()->value.':'.$e->subjectId()->toRfc4122()])) {
                return false;
            }

            // Class-level entries are always candidates; object entries only for ids in scope.
            return $e->isClassLevel() || \in_array($e->resourceId()?->toRfc4122(), $ids, true);
        }));
    }

    public function findClassLevelResourceClasses(SubjectSet $subjects, string $permissionName): array
    {
        $wanted = [];
        foreach ($subjects->pairs() as [$type, $id]) {
            $wanted[$type->value.':'.$id->toRfc4122()] = true;
        }

        $classes = [];

        foreach ($this->entries as $entry) {
            if (!$entry->isClassLevel() || $entry->permission()->name() !== $permissionName) {
                continue;
            }

            if (isset($wanted[$entry->subjectType()->value.':'.$entry->subjectId()->toRfc4122()])) {
                $classes[$entry->resourceClass()] = true;
            }
        }

        /** @var list<class-string> $result */
        $result = array_keys($classes);

        return $result;
    }

    public function save(AclEntry $entry): void
    {
        $this->add($entry);
    }

    public function remove(AclEntry $entry): void
    {
        $this->entries = array_values(array_filter(
            $this->entries,
            static fn (AclEntry $e): bool => !$e->id()->equals($entry->id()),
        ));
    }

    public function findById(Uuid $id): ?AclEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->id()->equals($id)) {
                return $entry;
            }
        }

        return null;
    }

    public function findPaginated(
        ?AclSubjectType $subjectType,
        ?Uuid $subjectId,
        ?string $resourceClass,
        int $offset,
        int $limit,
    ): array {
        return \array_slice($this->matching($subjectType, $subjectId, $resourceClass), $offset, $limit);
    }

    public function countFiltered(
        ?AclSubjectType $subjectType,
        ?Uuid $subjectId,
        ?string $resourceClass,
    ): int {
        return \count($this->matching($subjectType, $subjectId, $resourceClass));
    }

    public function findForResource(string $resourceClass, ?Uuid $resourceId): array
    {
        return array_values(array_filter($this->entries, static fn (AclEntry $e): bool => $e->resourceClass() === $resourceClass
            && $e->resourceId()?->toRfc4122() === $resourceId?->toRfc4122()));
    }

    /**
     * The one filter both paged methods share, so they cannot drift apart here either.
     *
     * @return list<AclEntry>
     */
    private function matching(?AclSubjectType $subjectType, ?Uuid $subjectId, ?string $resourceClass): array
    {
        return array_values(array_filter($this->entries, static function (AclEntry $e) use ($subjectType, $subjectId, $resourceClass): bool {
            if ($subjectType instanceof AclSubjectType && $e->subjectType() !== $subjectType) {
                return false;
            }

            if ($subjectId instanceof Uuid && !$e->subjectId()->equals($subjectId)) {
                return false;
            }

            return null === $resourceClass || $e->resourceClass() === $resourceClass;
        }));
    }
}
