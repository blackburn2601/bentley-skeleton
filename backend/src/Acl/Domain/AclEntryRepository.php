<?php

declare(strict_types=1);

namespace App\Acl\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * Reads and writes access-control entries.
 *
 * The interface lives in Domain and the Doctrine implementation in Infrastructure, so the
 * resolver can be unit-tested against an in-memory list rather than a database — which is
 * what makes an exhaustive decision-matrix test cheap enough to actually write.
 */
interface AclEntryRepository
{
    /**
     * Every entry that could bear on one check, in one query.
     *
     * Deliberately one round trip rather than one per tier: a collection endpoint rendering
     * fifty rows would otherwise issue hundreds of queries. The tier logic is then applied in
     * memory by PermissionResolver, where it is also testable without a database.
     *
     * Class-level entries (resource_id IS NULL) are always included — they are the last tier
     * of every check.
     *
     * @param list<Uuid> $resourceIds the object plus its ACL ancestors, most specific first
     *
     * @return list<AclEntry>
     */
    public function findCandidates(
        SubjectSet $subjects,
        string $resourceClass,
        array $resourceIds,
        string $permissionName,
    ): array;

    public function save(AclEntry $entry): void;

    public function remove(AclEntry $entry): void;

    public function findById(Uuid $id): ?AclEntry;

    /**
     * @return list<AclEntry>
     */
    public function findForResource(string $resourceClass, ?Uuid $resourceId): array;
}
