<?php

declare(strict_types=1);

namespace App\Acl\Infrastructure\Repository;

use App\Acl\Domain\UserGroup;
use App\Acl\Domain\UserGroupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineUserGroupRepository implements UserGroupRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function findById(Uuid $id): ?UserGroup
    {
        return $this->em->find(UserGroup::class, $id);
    }

    public function findByName(string $name): ?UserGroup
    {
        return $this->em->getRepository(UserGroup::class)->findOneBy(['name' => $name]);
    }

    public function findAll(): array
    {
        /** @var list<UserGroup> $groups */
        $groups = $this->em->getRepository(UserGroup::class)->findBy([], ['name' => 'ASC']);

        return $groups;
    }

    public function save(UserGroup $group): void
    {
        $this->em->persist($group);
    }

    public function remove(UserGroup $group): void
    {
        $this->em->remove($group);
    }
}
