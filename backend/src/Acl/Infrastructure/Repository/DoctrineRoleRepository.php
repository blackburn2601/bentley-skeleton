<?php

declare(strict_types=1);

namespace App\Acl\Infrastructure\Repository;

use App\Acl\Domain\Role;
use App\Acl\Domain\RoleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineRoleRepository implements RoleRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function findByName(string $name): ?Role
    {
        return $this->em->getRepository(Role::class)->findOneBy(['name' => $name]);
    }

    public function findById(Uuid $id): ?Role
    {
        return $this->em->find(Role::class, $id);
    }

    public function findAll(): array
    {
        /** @var list<Role> $roles */
        $roles = $this->em->getRepository(Role::class)->findBy([], ['name' => 'ASC']);

        return $roles;
    }

    public function anyGrants(array $roleIds, string $permissionName): bool
    {
        if ([] === $roleIds) {
            return false;
        }

        // EXISTS rather than fetching the roles and their permission collections: this runs
        // on every check that falls through to RBAC, and hydrating entities to answer a
        // yes/no question is the difference between one indexed lookup and N+1 loads.
        $count = $this->em->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Role::class, 'r')
            ->join('r.permissions', 'p')
            ->where('r.id IN (:roleIds)')
            ->andWhere('p.name = :permission')
            ->setParameter('roleIds', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $roleIds))
            ->setParameter('permission', $permissionName)
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    public function save(Role $role): void
    {
        $this->em->persist($role);
    }

    public function remove(Role $role): void
    {
        $this->em->remove($role);
    }
}
