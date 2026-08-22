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
    /**
     * Bypasses every check, and is itself audited for exactly that reason.
     *
     * A short-circuit like this is a liability: it means one row in one table removes all
     * authorization. It exists because the alternative — an admin locked out of the system
     * that grants access — is worse. Every use is written to the security event log.
     */
    public const string SUPER_ADMIN = 'ROLE_SUPER_ADMIN';

    /**
     * Held by every registered user, and the reason `/me` works.
     *
     * Without a baseline role, a freshly registered account has no grant of any kind and is
     * refused even the right to read itself — technically correct under a deny-by-default
     * ACL, and useless. This carries only the permissions a user has over their OWN account;
     * anything touching another user's data is a separate grant.
     */
    public const string DEFAULT_USER = 'ROLE_USER';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /** @var Collection<int, Permission> */
    #[ORM\ManyToMany(targetEntity: Permission::class)]
    #[ORM\JoinTable(name: 'role_permission')]
    private Collection $permissions;

    public function __construct(#[ORM\Column(type: 'string', length: 100, unique: true)]
        private string $name, #[ORM\Column(type: 'datetime_immutable')]
        private DateTimeImmutable $createdAt, #[ORM\Column(type: 'string', length: 255, nullable: true)]
        private ?string $description = null)
    {
        $this->id = Uuid::v7();
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
