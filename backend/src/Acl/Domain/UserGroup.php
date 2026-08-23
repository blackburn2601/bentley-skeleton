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

    /** @var Collection<int, Role> */
    #[ORM\ManyToMany(targetEntity: Role::class)]
    #[ORM\JoinTable(name: 'group_role')]
    private Collection $roles;

    public function __construct(#[ORM\Column(type: 'string', length: 100, unique: true)]
        private string $name, #[ORM\Column(type: 'datetime_immutable')]
        private DateTimeImmutable $createdAt, #[ORM\Column(type: 'string', length: 255, nullable: true)]
        private ?string $description = null)
    {
        $this->id = Uuid::v7();
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

    /**
     * Unlike a role name, a group name carries no meaning to the code — nothing matches on
     * it — so administrators may correct it.
     */
    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function describe(?string $description): void
    {
        $this->description = $description;
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
