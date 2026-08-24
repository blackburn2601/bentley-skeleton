<?php

declare(strict_types=1);

namespace App\Account\Infrastructure\Repository;

use App\Account\Domain\MfaRecoveryCode;
use App\Account\Domain\MfaRecoveryCodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineMfaRecoveryCodeRepository implements MfaRecoveryCodeRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function save(MfaRecoveryCode $code): void
    {
        $this->em->persist($code);
    }

    public function deleteAllForUser(Uuid $userId): void
    {
        $this->em->createQueryBuilder()
            ->delete(MfaRecoveryCode::class, 'c')
            ->where('IDENTITY(c.user) = :userId')
            ->setParameter('userId', $userId->toRfc4122())
            ->getQuery()
            ->execute();
    }

    public function findForUser(Uuid $userId, string $codeHash): ?MfaRecoveryCode
    {
        $result = $this->em->createQueryBuilder()
            ->select('c')
            ->from(MfaRecoveryCode::class, 'c')
            ->where('IDENTITY(c.user) = :userId')
            ->andWhere('c.codeHash = :codeHash')
            ->setParameter('userId', $userId->toRfc4122())
            ->setParameter('codeHash', $codeHash)
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof MfaRecoveryCode ? $result : null;
    }
}
