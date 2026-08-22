<?php

declare(strict_types=1);

namespace App\Acl\Domain;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A role held by a user.
 *
 * Keyed by user *id* rather than by a User association, because User belongs to the Account
 * context and Acl may not depend on its internals. The database still enforces the foreign
 * key — that constraint is declared in the migration, where it costs nothing.
 *
 * The practical benefit is that authorization state has exactly one owner. If Account also
 * mapped this association, two contexts could write the same rows and only one of them would
 * remember to bump `acl_version`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_role')]
#[ORM\UniqueConstraint(name: 'uniq_user_role', columns: ['user_id', 'role_id'])]
#[ORM\Index(name: 'idx_user_role_user', columns: ['user_id'])]
class UserRole
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $userId;

    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Role $role;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(Uuid $userId, Role $role, DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->userId = $userId;
        $this->role = $role;
        $this->createdAt = $now;
    }

    public function userId(): Uuid
    {
        return $this->userId;
    }

    public function role(): Role
    {
        return $this->role;
    }
}
