<?php

declare(strict_types=1);

namespace App\Account\Infrastructure\Repository;

use App\Account\Domain\RefreshToken;
use App\Account\Domain\RefreshTokenRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineRefreshTokenRepository implements RefreshTokenRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function findByHash(string $tokenHash): ?RefreshToken
    {
        $result = $this->em->createQueryBuilder()
            ->select('t', 'u')
            ->from(RefreshToken::class, 't')
            // Fetch-join the user: every caller needs it, and refresh is on the hot path.
            ->join('t.user', 'u')
            ->where('t.tokenHash = :hash')
            ->setParameter('hash', $tokenHash)
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof RefreshToken ? $result : null;
    }

    public function save(RefreshToken $token): void
    {
        $this->em->persist($token);
    }

    public function revokeFamily(Uuid $familyId, DateTimeImmutable $now): int
    {
        // Bulk UPDATE rather than loading and mutating entities: this runs at the moment a
        // token theft is detected, and it must be one statement regardless of how long the
        // chain grew.
        return (int) $this->em->createQueryBuilder()
            ->update(RefreshToken::class, 't')
            ->set('t.revokedAt', ':now')
            ->where('t.familyId = :familyId')
            ->andWhere('t.revokedAt IS NULL')
            ->setParameter('now', $now)
            ->setParameter('familyId', $familyId->toRfc4122())
            ->getQuery()
            ->execute();
    }

    public function revokeAllForUser(Uuid $userId, DateTimeImmutable $now): int
    {
        return (int) $this->em->createQueryBuilder()
            ->update(RefreshToken::class, 't')
            ->set('t.revokedAt', ':now')
            ->where('IDENTITY(t.user) = :userId')
            ->andWhere('t.revokedAt IS NULL')
            ->setParameter('now', $now)
            ->setParameter('userId', $userId->toRfc4122())
            ->getQuery()
            ->execute();
    }

    public function findActiveSessionsForUser(Uuid $userId, DateTimeImmutable $now): array
    {
        // The newest token in each family represents that session. Showing every token would
        // list one row per refresh — hundreds for an active user, all the same device.
        /** @var list<RefreshToken> $tokens */
        $tokens = $this->em->createQueryBuilder()
            ->select('t')
            ->from(RefreshToken::class, 't')
            ->where('IDENTITY(t.user) = :userId')
            ->andWhere('t.revokedAt IS NULL')
            ->andWhere('t.usedAt IS NULL')
            ->andWhere('t.expiresAt > :now')
            ->setParameter('userId', $userId->toRfc4122())
            ->setParameter('now', $now)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $tokens;
    }

    public function deleteExpired(DateTimeImmutable $before): int
    {
        return (int) $this->em->createQueryBuilder()
            ->delete(RefreshToken::class, 't')
            ->where('t.expiresAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
