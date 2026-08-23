<?php

declare(strict_types=1);

namespace App\Api\Acl\Response;

/**
 * The people in one group.
 */
final readonly class ListGroupMembersResponse
{
    /**
     * @param list<array{id: string, email: string}> $items
     */
    private function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
    ) {
    }

    /**
     * @param list<array{id: string, email: string}> $members
     */
    public static function from(array $members): self
    {
        $total = \count($members);

        return new self($members, 1, max(1, $total), $total);
    }
}
