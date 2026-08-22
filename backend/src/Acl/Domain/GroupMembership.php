<?php

declare(strict_types=1);

namespace App\Acl\Domain;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A user's membership of a group. Keyed by user id — see UserRole for why.
 */
#[ORM\Entity]
#[ORM\Table(name: 'group_membership')]
#[ORM\UniqueConstraint(name: 'uniq_group_membership', columns: ['user_id', 'group_id'])]
#[ORM\Index(name: 'idx_group_membership_user', columns: ['user_id'])]
class GroupMembership
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $userId;

    #[ORM\ManyToOne(targetEntity: UserGroup::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private UserGroup $group;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(Uuid $userId, UserGroup $group, DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->userId = $userId;
        $this->group = $group;
        $this->createdAt = $now;
    }

    public function userId(): Uuid
    {
        return $this->userId;
    }

    public function group(): UserGroup
    {
        return $this->group;
    }
}
