<?php

declare(strict_types=1);

namespace App\Acl\Application;

use App\Acl\Domain\AclEffect;
use App\Acl\Domain\AclEntry;
use App\Acl\Domain\AclEntryRepository;
use App\Acl\Domain\AclParentAware;
use App\Acl\Domain\AclTier;
use App\Acl\Domain\PermissionDecision;
use App\Acl\Domain\RoleRepository;
use App\Acl\Domain\SubjectRepository;
use App\Acl\Domain\SubjectSet;
use App\Shared\Domain\Clock;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Decides whether a subject holds a permission on a resource.
 *
 * The algorithm (ADR-0003), most specific first. Within a tier, **deny beats allow**; if a
 * tier says nothing, evaluation falls through to the next:
 *
 *   0. ROLE_SUPER_ADMIN short-circuits, and is audited for doing so.
 *   1. Entries on the object itself.
 *   2. Entries on each ACL ancestor, nearest first (a grant on a folder covers its notes).
 *   3. Class-level entries (resource_id IS NULL) — "may read every Note".
 *   4. RBAC fallback: does any effective role carry this permission?
 *   5. Denied.
 *
 * Deny-beats-allow *within* a tier rather than globally is the important detail. A global
 * deny precedence would make a broad "deny all" impossible to carve exceptions out of; tiered
 * precedence means a specific grant on one object can override a general refusal, which is
 * what "share this one document with the contractor" requires. And because precedence is by
 * tier rather than by row order, the outcome never depends on which row was inserted first.
 *
 * Everything is decided from a single query (see AclEntryRepository::findCandidates), then
 * memoised per request and cached in Redis under a key containing the user's acl_version, so
 * a grant change takes effect on the next request with no invalidation sweep (ADR-0011).
 */
final readonly class PermissionResolver
{
    /** How deep an inheritance chain is walked before we refuse to keep going. */
    private const int MAX_PARENT_DEPTH = 10;

    public function __construct(
        private AclEntryRepository $entries,
        private RoleRepository $roles,
        private SubjectRepository $subjects,
        private Clock $clock,
        private AclCache $cache,
    ) {
    }

    /**
     * @param object|class-string|null $resource an object walks every tier; a CLASS NAME asks
     *                                           the class-level question ("may they read Users
     *                                           in general?"); null asks only whether a role
     *                                           carries the permission at all
     */
    public function isGranted(Uuid $userId, string $permission, object|string|null $resource = null): bool
    {
        return $this->cache->remember(
            $userId,
            $permission,
            $resource,
            fn (): bool => $this->decide($userId, $permission, $resource)->granted,
        );
    }

    /**
     * The same decision, with its reasoning — and never cached.
     *
     * An explanation is only useful if it describes what is happening *now*; a cached one
     * would answer for whenever the cache was filled, which is precisely the confusion this
     * method exists to resolve.
     */
    public function explain(Uuid $userId, string $permission, object|string|null $resource = null): PermissionDecision
    {
        return $this->decide($userId, $permission, $resource);
    }

    private function decide(Uuid $userId, string $permission, object|string|null $resource): PermissionDecision
    {
        $subjects = $this->subjects->subjectSetFor($userId);

        if ($subjects->isSuperAdmin()) {
            return PermissionDecision::granted(AclTier::SuperAdmin, null, 'ROLE_SUPER_ADMIN bypasses all checks');
        }

        // A class name means "the class-level question": consult entries with resource_id
        // NULL for that class, then fall back to roles. Passing null instead would skip the
        // entry tiers entirely, because there would be no class to look them up by — which is
        // precisely the bug the resolver/criteria cross-check test caught.
        $resourceClass = match (true) {
            null === $resource => null,
            \is_string($resource) => $resource,
            default => $this->classOf($resource),
        };

        $chain = \is_object($resource) ? $this->parentChain($resource) : [];

        if (null !== $resourceClass) {
            $decision = $this->decideFromEntries($subjects, $resourceClass, $chain, $permission);

            if ($decision instanceof PermissionDecision) {
                return $decision;
            }
        }

        return $this->rbacFallback($subjects, $permission);
    }

    /**
     * Tiers 1-3. Null means "no tier had an opinion" — fall through to RBAC.
     *
     * @param list<Uuid> $chain object id first, then ancestors
     */
    private function decideFromEntries(
        SubjectSet $subjects,
        string $resourceClass,
        array $chain,
        string $permission,
    ): ?PermissionDecision {
        $candidates = $this->entries->findCandidates($subjects, $resourceClass, $chain, $permission);

        if ([] === $candidates) {
            return null;
        }

        $now = $this->clock->now();

        // Expired entries are ignored rather than deleted, so "who had access in March?"
        // stays answerable.
        $live = array_values(array_filter(
            $candidates,
            static fn (AclEntry $entry): bool => $entry->isEffectiveAt($now),
        ));

        // Tier by tier, most specific first: each ancestor in order, then class-level.
        foreach ($chain as $depth => $resourceId) {
            $tierEntries = array_values(array_filter(
                $live,
                static fn (AclEntry $e): bool => $e->resourceId() instanceof Uuid && $e->resourceId()->equals($resourceId),
            ));

            $decision = $this->settle($tierEntries, 0 === $depth ? AclTier::Object : AclTier::Inherited);

            if ($decision instanceof PermissionDecision) {
                return $decision;
            }
        }

        $classLevel = array_values(array_filter($live, static fn (AclEntry $e): bool => $e->isClassLevel()));

        return $this->settle($classLevel, AclTier::ClassLevel);
    }

    /**
     * One tier's verdict: any deny wins, then any allow, otherwise silence.
     *
     * @param list<AclEntry> $entries
     */
    private function settle(array $entries, AclTier $tier): ?PermissionDecision
    {
        foreach ($entries as $entry) {
            if (AclEffect::Deny === $entry->effect()) {
                return PermissionDecision::denied($tier, $entry, 'an explicit deny at this level');
            }
        }

        foreach ($entries as $entry) {
            if (AclEffect::Allow === $entry->effect()) {
                return PermissionDecision::granted($tier, $entry);
            }
        }

        return null;
    }

    private function rbacFallback(SubjectSet $subjects, string $permission): PermissionDecision
    {
        if ([] !== $subjects->roleIds && $this->roles->anyGrants($subjects->roleIds, $permission)) {
            return PermissionDecision::granted(AclTier::Rbac);
        }

        return PermissionDecision::denied(AclTier::Default);
    }

    /**
     * The object's id followed by its ancestors' ids, nearest first.
     *
     * @return list<Uuid>
     */
    private function parentChain(object $resource): array
    {
        $chain = [];
        $seen = [];
        $current = $resource;
        $depth = 0;

        while (null !== $current && $depth < self::MAX_PARENT_DEPTH) {
            $id = $this->identify($current);

            if (!$id instanceof Uuid) {
                break;
            }

            // A cycle (A parents B parents A) would otherwise loop until the depth guard,
            // silently doing the same work repeatedly on every single check.
            $key = $this->classOf($current).':'.$id->toRfc4122();
            if (isset($seen[$key])) {
                break;
            }
            $seen[$key] = true;

            $chain[] = $id;

            $current = $current instanceof AclParentAware ? $current->getAclParent() : null;
            ++$depth;
        }

        return $chain;
    }

    private function identify(object $resource): ?Uuid
    {
        if (!method_exists($resource, 'id')) {
            return null;
        }

        $id = $resource->id();

        return $id instanceof Uuid ? $id : null;
    }

    /**
     * The real class, not the Doctrine proxy subclass.
     *
     * A lazily-loaded entity is an instance of `Proxies\__CG__\App\...`, and storing that in
     * `acl_entry.resource_class` would produce grants that never match anything loaded
     * eagerly. This is the classic per-object-ACL bug that only shows up in production.
     */
    private function classOf(object $resource): string
    {
        $class = $resource::class;
        $marker = strpos($class, '\\__CG__\\');

        return false === $marker ? $class : substr($class, $marker + 8);
    }
}
