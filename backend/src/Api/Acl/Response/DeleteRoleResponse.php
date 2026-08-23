<?php

declare(strict_types=1);

namespace App\Api\Acl\Response;

/**
 * What was removed, named so the client can confirm it in a toast.
 */
final readonly class DeleteRoleResponse
{
    private function __construct(
        public string $name,
        public bool $deleted,
    ) {
    }

    public static function from(string $name): self
    {
        return new self($name, true);
    }
}
