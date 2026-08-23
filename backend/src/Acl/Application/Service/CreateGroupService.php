<?php

declare(strict_types=1);

namespace App\Acl\Application\Service;

use App\Acl\Domain\AclException;
use App\Acl\Domain\UserGroup;
use App\Acl\Domain\UserGroupRepository;
use App\Shared\Domain\Clock;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @responsibility Creates a named group.
 */
final readonly class CreateGroupService
{
    public function __construct(
        private UserGroupRepository $groups,
        private Clock $clock,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(string $name, ?string $description): UserGroup
    {
        $name = trim($name);

        if ($this->groups->findByName($name) instanceof UserGroup) {
            throw AclException::groupNameTaken($name);
        }

        $group = new UserGroup($name, $this->clock->now(), $description);
        $this->groups->save($group);
        $this->em->flush();

        return $group;
    }
}
