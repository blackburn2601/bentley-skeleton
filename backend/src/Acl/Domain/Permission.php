<?php

declare(strict_types=1);

namespace App\Acl\Domain;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A permission that exists in this system, as a row.
 *
 * The authoritative list is PermissionCatalog, in code. These rows are its projection, kept
 * in step by `app:acl:sync-permissions`, and exist so that `acl_entry` can reference a
 * permission by foreign key rather than by a string nobody validates.
 */
#[ORM\Entity]
#[ORM\Table(name: 'permission')]
class Permission
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    public function __construct(#[ORM\Column(type: 'string', length: 100, unique: true)]
        private string $name, #[ORM\Column(type: 'datetime_immutable')]
        private DateTimeImmutable $createdAt)
    {
        $this->id = Uuid::v7();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }
}
