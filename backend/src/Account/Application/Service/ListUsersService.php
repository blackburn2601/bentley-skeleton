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
            // citext makes the column case-insensitive, so LOWER() would only muddle the intent.
            $qb->andWhere('u.username LIKE :search')
                ->setParameter('search', '%'.$search.'%');
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
