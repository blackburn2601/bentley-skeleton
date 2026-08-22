<?php

declare(strict_types=1);

namespace App\Acl\Infrastructure\Repository;

use App\Acl\Domain\Permission;
use App\Acl\Domain\PermissionRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrinePermissionRepository implements PermissionRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function findByName(string $name): ?Permission
    {
        return $this->em->getRepository(Permission::class)->findOneBy(['name' => $name]);
    }

    public function findAll(): array
    {
        /** @var list<Permission> $permissions */
        $permissions = $this->em->getRepository(Permission::class)->findBy([], ['name' => 'ASC']);

        return $permissions;
    }

    public function findAllNames(): array
    {
        /** @var list<array{name: string}> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('p.name')
            ->from(Permission::class, 'p')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'name');
    }

    public function save(Permission $permission): void
    {
        $this->em->persist($permission);
    }
}
