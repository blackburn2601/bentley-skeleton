<?php

declare(strict_types=1);

namespace App\Account\Infrastructure\Repository;

use App\Account\Domain\SingleUseToken;
use App\Account\Domain\SingleUseTokenRepository;
use App\Account\Domain\TokenPurpose;
use App\Account\Domain\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineSingleUseTokenRepository implements SingleUseTokenRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function findByHash(string $tokenHash): ?SingleUseToken
    {
        $result = $this->em->createQueryBuilder()
            ->select('t', 'u')
            ->from(SingleUseToken::class, 't')
            ->join('t.user', 'u')
            ->where('t.tokenHash = :hash')
            ->setParameter('hash', $tokenHash)
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof SingleUseToken ? $result : null;
    }

    public function save(SingleUseToken $token): void
    {
        $this->em->persist($token);
    }

    public function consumeOutstanding(User $user, TokenPurpose $purpose, DateTimeImmutable $now): void
    {
        $this->em->createQueryBuilder()
            ->update(SingleUseToken::class, 't')
            ->set('t.consumedAt', ':now')
            ->where('IDENTITY(t.user) = :userId')
            ->andWhere('t.purpose = :purpose')
            ->andWhere('t.consumedAt IS NULL')
            ->setParameter('now', $now)
            ->setParameter('userId', $user->id()->toRfc4122())
            ->setParameter('purpose', $purpose->value)
            ->getQuery()
            ->execute();
    }

    public function deleteExpired(DateTimeImmutable $before): int
    {
        return (int) $this->em->createQueryBuilder()
            ->delete(SingleUseToken::class, 't')
            ->where('t.expiresAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
