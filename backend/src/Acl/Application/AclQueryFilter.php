<?php

declare(strict_types=1);

namespace App\Acl\Application;

use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Uuid;

/**
 * Narrows a query to the rows one caller is permitted to see.
 *
 * A port, not a convenience wrapper. The implementation (AclCriteriaBuilder) lives in
 * Infrastructure because it writes DQL against acl_entry, and Infrastructure is where
 * persistence detail belongs — but a list endpoint's service is in Application, and
 * Application must not depend on Infrastructure (INV-01). Without this interface there is no
 * legal path from a collection endpoint to ACL filtering at all, so every list endpoint would
 * have to filter in PHP after the query, which is the exact bug AclCriteriaBuilder's docblock
 * exists to prevent: rows removed after LIMIT, pages that come back short, and a total that
 * is a lie.
 *
 * This is a real port boundary (a Doctrine adapter behind an interface), not the
 * interface-per-service ceremony INV-12 forbids.
 *
 * Doctrine's QueryBuilder in an Application signature is the same compromise the rest of this
 * layer already makes — RegisterUserService and eight of its neighbours inject
 * EntityManagerInterface — rather than a new one introduced here.
 */
interface AclQueryFilter
{
    /**
     * Add the permission predicate for $userId to $qb, in place.
     *
     * A caller who may see everything (super admin) has no predicate added, so the query is
     * left exactly as it was.
     */
    public function apply(QueryBuilder $qb, string $alias, string $permission, Uuid $userId): void;
}
