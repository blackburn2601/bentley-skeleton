<?php

declare(strict_types=1);

namespace App\Account\Infrastructure\Repository;

use App\Account\Domain\User;
use App\Account\Domain\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineUserRepository implements UserRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function findById(Uuid $id): ?User
    {
        return $this->em->find(User::class, $id);
    }

    public function findByUsername(string $username): ?User
    {
        // No strtolower() here on purpose: the column is citext, so the database compares
        // case-insensitively. Normalising in PHP as well would hide that, and the next query
        // written without it would behave differently for no visible reason.
        return $this->em->getRepository(User::class)->findOneBy(['username' => $username]);
    }

    public function existsByUsername(string $username): bool
    {
        $count = $this->em->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(User::class, 'u')
            ->where('u.username = :username')
            ->setParameter('username', $username)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    public function save(User $user): void
    {
        $this->em->persist($user);
    }

    public function findAllPaginated(int $offset, int $limit): array
    {
        /** @var list<User> $users */
        $users = $this->em->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->orderBy('u.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $users;
    }

    public function countAll(): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(User::class, 'u')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
