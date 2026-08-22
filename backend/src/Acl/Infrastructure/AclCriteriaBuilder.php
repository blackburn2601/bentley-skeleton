<?php

declare(strict_types=1);

namespace App\Acl\Infrastructure;

use App\Acl\Application\PermissionResolver;
use App\Acl\Domain\AclEffect;
use App\Acl\Domain\AclEntry;
use App\Acl\Domain\AclParentAware;
use App\Acl\Domain\AclTier;
use App\Acl\Domain\RoleRepository;
use App\Acl\Domain\SubjectRepository;
use App\Acl\Domain\SubjectSet;
use App\Shared\Domain\Clock;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\Uid\Uuid;

/**
 * Pushes a permission check into SQL, so a list shows exactly what its owner may see.
 *
 * The alternative — fetch rows, then filter in PHP — is wrong in a way that looks fine in
 * development: page 1 quietly returns three of twenty rows because seventeen were filtered
 * after the LIMIT, and the total count is a lie.
 *
 * **The hard requirement is that this agrees with PermissionResolver on every row.** A list
 * that shows something a direct fetch would refuse is an authorization bug; one that hides
 * something a direct fetch allows is a support ticket. That agreement is asserted by an
 * integration test that runs both against the same data.
 *
 * Achieving it takes some care, because the resolver decides tier by tier and the obvious
 * SQL ("EXISTS an allow AND NOT EXISTS a deny") does not. That flat form makes deny globally
 * dominant, so a class-level deny would beat an object-level allow — while the resolver, and
 * every user's expectation, has the more specific grant win. So:
 *
 *   - The **object tier** is per-row, and becomes SQL: deny wins, then allow.
 *   - The **class tier** and the **RBAC fallback** are the same for every row in the result,
 *     so they are evaluated once in PHP and collapse into a single boolean.
 *
 * That leaves exactly the resolver's algorithm, expressed as:
 *
 *     CASE WHEN EXISTS(object deny)  THEN false
 *          WHEN EXISTS(object allow) THEN true
 *          ELSE <constant fallback>  END
 *
 * ### Inheritance is not supported here, loudly
 *
 * If the entity implements AclParentAware, the tier list is different for every row and
 * cannot be expressed without a recursive query. Rather than return subtly wrong rows, this
 * throws. The documented ways forward are in docs/cookbook/add-entity-with-acl.md: filter
 * without inheritance, or enable the denormalised `acl_effective` projection.
 */
final readonly class AclCriteriaBuilder
{
    public function __construct(
        private SubjectRepository $subjects,
        private RoleRepository $roles,
        private PermissionResolver $resolver,
        private Clock $clock,
    ) {
    }

    /**
     * Constrain $qb so it returns only rows $userId may access under $permission.
     *
     * @param string $alias the root alias already present in the query builder
     */
    public function apply(QueryBuilder $qb, string $alias, string $permission, Uuid $userId): void
    {
        $entityClass = $this->rootEntityClass($qb, $alias);
        $this->refuseInheritance($entityClass);

        $subjects = $this->subjects->subjectSetFor($userId);

        // Super admin sees everything; adding predicates would only slow the query down.
        if ($subjects->isSuperAdmin()) {
            return;
        }

        $fallbackGrants = $this->fallbackGrants($subjects, $entityClass, $permission);

        $qb->setParameter('aclNow', $this->clock->now());
        $qb->setParameter('aclClass', $entityClass);
        $qb->setParameter('aclPermission', $permission);
        $this->bindSubjects($qb, $subjects);

        $denyAtObject = $this->existsSubquery($subjects, $alias, AclEffect::Deny);

        if ($fallbackGrants) {
            // Already permitted in general: the only thing that can take it away is an
            // explicit deny on this particular row.
            $qb->andWhere('NOT '.$denyAtObject);

            return;
        }

        // Not permitted in general: the row must be granted explicitly, and not denied.
        $qb->andWhere($this->existsSubquery($subjects, $alias, AclEffect::Allow))
            ->andWhere('NOT '.$denyAtObject);
    }

    /**
     * The tiers that are the same for every row: class-level entries, then RBAC.
     *
     * Asking the resolver with a null resource gives exactly that, and reusing it here is
     * deliberate — two implementations of "what does the class tier say?" is precisely how
     * the list and the item check drift apart.
     */
    /**
     * @param class-string $entityClass
     */
    private function fallbackGrants(SubjectSet $subjects, string $entityClass, string $permission): bool
    {
        // The CLASS, not null. Asking with null skips the class-level entries entirely —
        // there would be no class to look them up by — so a caller holding a class-level
        // grant would get an empty list while a direct check granted every row. Exactly
        // the disagreement this builder exists to avoid, and what the cross-check caught.
        $classLevel = $this->resolver->explain($subjects->userId, $permission, $entityClass);

        // A class-level DENY is decisive only against the fallback; an object-level allow can
        // still override it, which the SQL above preserves.
        if (AclTier::ClassLevel === $classLevel->tier) {
            return $classLevel->granted;
        }

        return $classLevel->granted
            || ([] !== $subjects->roleIds && $this->roles->anyGrants($subjects->roleIds, $permission));
    }

    private function existsSubquery(SubjectSet $subjects, string $alias, AclEffect $effect): string
    {
        $suffix = $effect->value;

        return \sprintf(
            'EXISTS (SELECT 1 FROM %s %s_e JOIN %s_e.permission %s_p WHERE %s)',
            AclEntry::class,
            $suffix,
            $suffix,
            $suffix,
            implode(' AND ', [
                \sprintf('%s_e.resourceClass = :aclClass', $suffix),
                \sprintf('%s_e.resourceId = %s.id', $suffix, $alias),
                \sprintf('%s_p.name = :aclPermission', $suffix),
                \sprintf("%s_e.effect = '%s'", $suffix, $effect->value),
                \sprintf('(%s_e.expiresAt IS NULL OR %s_e.expiresAt > :aclNow)', $suffix, $suffix),
                '('.$this->subjectPredicate($subjects, $suffix.'_e').')',
            ]),
        );
    }

    private function subjectPredicate(SubjectSet $subjects, string $alias): string
    {
        $clauses = [];

        foreach (array_keys($subjects->pairs()) as $index) {
            $clauses[] = \sprintf('(%s.subjectType = :aclSt%d AND %s.subjectId = :aclSi%d)', $alias, $index, $alias, $index);
        }

        return implode(' OR ', $clauses);
    }

    private function bindSubjects(QueryBuilder $qb, SubjectSet $subjects): void
    {
        foreach ($subjects->pairs() as $index => [$type, $id]) {
            $qb->setParameter('aclSt'.$index, $type->value);
            $qb->setParameter('aclSi'.$index, $id->toRfc4122());
        }
    }

    /**
     * @return class-string
     */
    private function rootEntityClass(QueryBuilder $qb, string $alias): string
    {
        foreach ($qb->getRootAliases() as $index => $rootAlias) {
            if ($rootAlias === $alias) {
                return $qb->getRootEntities()[$index];
            }
        }

        throw new InvalidArgumentException(\sprintf('Alias "%s" is not a root alias of this query. AclCriteriaBuilder filters the root entity; pass the alias you selected FROM.', $alias));
    }

    private function refuseInheritance(string $entityClass): void
    {
        if (!is_a($entityClass, AclParentAware::class, true)) {
            return;
        }

        throw new LogicException(\sprintf('%s implements AclParentAware, so its permissions can be inherited from a parent object. Collection filtering cannot express that in SQL — each row would need its own ancestor chain — and returning rows that ignore inheritance would make this list disagree with PermissionResolver, which is the one thing it must never do. See docs/cookbook/add-entity-with-acl.md for the two supported options: drop the parent relationship, or enable the acl_effective projection.', $entityClass));
    }
}
