<?php

declare(strict_types=1);

namespace App\Acl\Domain;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A set of users that can be granted things as a unit.
 *
 * Groups exist so that "the people on this project" is one subject rather than a list that
 * has to be re-granted every time someone joins. Membership is stored as user *ids* —
 * see GroupMembership for why.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_group')]
class UserGroup
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'string', length: 100, unique: true)]
    private string $name;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $description;

    /** @var Collection<int, Role> */
    #[ORM\ManyToMany(targetEntity: Role::class)]
    #[ORM\JoinTable(name: 'group_role')]
    private Collection $roles;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(string $name, DateTimeImmutable $now, ?string $description = null)
    {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->description = $description;
        $this->createdAt = $now;
        $this->roles = new ArrayCollection();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    /** @return list<Role> */
    public function roles(): array
    {
        return array_values($this->roles->toArray());
    }

    public function assignRole(Role $role): void
    {
        if (!$this->roles->contains($role)) {
            $this->roles->add($role);
        }
    }

    public function revokeRole(Role $role): void
    {
        $this->roles->removeElement($role);
    }
}
