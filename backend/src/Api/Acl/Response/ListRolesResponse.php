<?php

declare(strict_types=1);

namespace App\Api\Acl\Response;

/**
 * Every role, with the permissions it carries.
 *
 * Carries the full pagination envelope even though roles are never paged, so that every
 * collection in this API has one shape and the SPA needs one type (ADR-0019).
 */
final readonly class ListRolesResponse
{
    /**
     * @param list<array{id: string, name: string, description: string|null, permissions: list<string>}> $items
     */
    private function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
    ) {
    }

    /**
     * @param list<array{id: string, name: string, description: string|null, permissions: list<string>}> $roles
     */
    public static function from(array $roles): self
    {
        $total = \count($roles);

        return new self($roles, 1, max(1, $total), $total);
    }
}
