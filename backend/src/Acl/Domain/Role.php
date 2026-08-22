<?php

declare(strict_types=1);

namespace App\Acl\Domain;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A named bundle of permissions.
 *
 * Roles are the coarse layer and remain useful: most access is "everyone in Support can read
 * tickets", and expressing that per object would be absurd. The per-object ACL exists for
 * the cases roles cannot express, not to replace them — the resolver falls back to roles as
 * its final tier (ADR-0003).
 */
#[ORM\Entity]
#[ORM\Table(name: 'role')]
class Role
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'string', length: 100, unique: true)]
    private string $name;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $description;

    /** @var Collection<int, Permission> */
    #[ORM\ManyToMany(targetEntity: Permission::class)]
    #[ORM\JoinTable(name: 'role_permission')]
    private Collection $permissions;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(string $name, DateTimeImmutable $now, ?string $description = null)
    {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->description = $description;
        $this->createdAt = $now;
        $this->permissions = new ArrayCollection();
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

    /** @return list<Permission> */
    public function permissions(): array
    {
        return array_values($this->permissions->toArray());
    }

    public function grant(Permission $permission): void
    {
        if (!$this->permissions->contains($permission)) {
            $this->permissions->add($permission);
        }
    }

    public function revoke(Permission $permission): void
    {
        $this->permissions->removeElement($permission);
    }
}
