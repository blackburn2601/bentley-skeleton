<?php

declare(strict_types=1);

namespace App\Acl\Domain;

/**
 * An object that inherits permissions from another object.
 *
 * A grant on a folder should cover the notes inside it; otherwise sharing anything
 * containing other things means enumerating its contents, and re-enumerating on every
 * addition.
 *
 * Returning null is a legitimate answer and an explicit one: this object inherits nothing,
 * so only object-level and class-level grants apply to it. Implementing the interface and
 * returning null says "considered"; not implementing it says nothing.
 *
 * Keep the chain short. The resolver walks it on every check, and a deep hierarchy turns one
 * permission decision into many.
 */
interface AclParentAware
{
    public function getAclParent(): ?object;
}
