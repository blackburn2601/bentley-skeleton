<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Domain\User;
use App\Account\Domain\UserStatus;
use App\Acl\Application\AclFacade;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Lists the user accounts a caller is permitted to read.
 *
 * The permission check happens in SQL, not in PHP. Fetching a page and then discarding the
 * rows the caller may not see returns a short page with a total that is a lie, and lets this
 * list disagree with a direct fetch of the same row — the classic per-object-ACL bug that
 * AclConsistencyTest exists to catch.
 */
final readonly class ListUsersService
{
    public function __construct(
        private EntityManagerInterface $em,
        private AclFacade $acl,
    ) {
    }

    /**
     * The permission name is passed in rather than named here: PermissionCatalog is the Acl
     * context's own vocabulary, and Account may only reach Acl through its facade (INV-02).
     * The controller supplies it, which also keeps it next to the #[IsGranted] it must match.
     *
     * @return array{items: list<User>, total: int}
     */
    public function __invoke(
        Uuid $callerId,
        string $permission,
        int $offset,
        int $limit,
        ?string $search = null,
        ?UserStatus $status = null,
    ): array {
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u');

        if (null !== $search && '' !== $search) {
            // Username OR id. The id is what the list shows in its first column, and someone
            // holding one quoted from a log line or an audit row has no username to type.
            //
            // The two sides are matched differently because the columns are. citext makes the
            // username case-insensitive already, so LOWER() there would only muddle the
            // intent. The id is a `uuid`, which cannot be pattern-matched at all, so TEXT()
            // renders it in its canonical form first (see TextCastFunction). PostgreSQL writes
            // that form in lowercase while an id pasted from elsewhere may well arrive
            // uppercased, so the id side is lowered on both ends.
            //
            // Parenthesised explicitly. Doctrine does bracket a part containing OR (DDC-1237),
            // but the ACL predicate is ANDed on below, and an OR that escaped its brackets
            // there would widen the page past what the caller is allowed to see. That is not a
            // thing to leave to a regex in the query builder.
            $qb->andWhere('(u.username LIKE :search OR TEXT(u.id) LIKE :idSearch)')
                ->setParameter('search', '%'.$search.'%')
                ->setParameter('idSearch', '%'.strtolower($search).'%');
        }

        if ($status instanceof UserStatus) {
            $qb->andWhere('u.status = :status')->setParameter('status', $status->value);
        }

        $this->acl->filterToVisible($qb, 'u', $permission, $callerId);

        // Cloned AFTER the ACL predicate, so the count describes the same rows the page does.
        // Cloning before is how "1-25 of 348" ends up rendering four rows.
        $total = (int) (clone $qb)
            ->select('COUNT(u.id)')
            ->setFirstResult(0)
            ->setMaxResults(null)
            ->getQuery()
            ->getSingleScalarResult();

        /** @var list<User> $items */
        $items = $qb
            ->orderBy('u.username', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }
}
