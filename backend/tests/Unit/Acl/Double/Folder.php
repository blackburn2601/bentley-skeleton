<?php

declare(strict_types=1);

namespace App\Tests\Unit\Acl\Double;

use App\Acl\Domain\AclParentAware;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final readonly class Folder implements AclParentAware
{
    private Uuid $id;

    public function __construct(private ?self $parent = null)
    {
        $this->id = Uuid::v7();
    }

    public function id(): UuidV7
    {
        return $this->id;
    }

    public function getAclParent(): ?object
    {
        return $this->parent;
    }
}
