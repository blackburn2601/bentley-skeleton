<?php

declare(strict_types=1);

namespace App\Api\Acl\Response;

/**
 * Every group, with the roles it carries and how many people are in it.
 */
final readonly class ListGroupsResponse
{
    /**
     * @param list<array{id: string, name: string, description: string|null, roles: list<string>, memberCount: int}> $items
     */
    private function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
    ) {
    }

    /**
     * @param list<array{id: string, name: string, description: string|null, roles: list<string>, memberCount: int}> $groups
     */
    public static function from(array $groups): self
    {
        $total = \count($groups);

        return new self($groups, 1, max(1, $total), $total);
    }
}
