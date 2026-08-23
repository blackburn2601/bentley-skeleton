<?php

declare(strict_types=1);

namespace App\Acl\Application\Service;

use App\Acl\Domain\AclException;
use App\Acl\Domain\UserGroup;
use App\Acl\Domain\UserGroupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Applies an administrator's edits to one group's descriptive fields.
 *
 * A group name may be corrected, unlike a role name: nothing in the code matches on it, and it
 * appears in no token.
 */
final readonly class UpdateGroupService
{
    public function __construct(
        private UserGroupRepository $groups,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(Uuid $groupId, string $name, ?string $description): UserGroup
    {
        $group = $this->groups->findById($groupId);

        if (!$group instanceof UserGroup) {
            throw AclException::noSuchGroup();
        }

        $name = trim($name);
        $clash = $this->groups->findByName($name);

        if ($clash instanceof UserGroup && !$clash->id()->equals($group->id())) {
            throw AclException::groupNameTaken($name);
        }

        $group->rename($name);
        $group->describe($description);
        $this->em->flush();

        return $group;
    }
}
