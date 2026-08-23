<?php

declare(strict_types=1);

namespace App\Api\Acl\Response;

/**
 * The permission catalogue, as rows in the database.
 *
 * Full envelope for one response shape across the API (ADR-0019), even though the catalogue is
 * a closed set that is never paged.
 */
final readonly class ListPermissionsResponse
{
    /**
     * @param list<array{id: string, name: string, resource: string, action: string}> $items
     */
    private function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
    ) {
    }

    /**
     * @param list<array{id: string, name: string, resource: string, action: string}> $permissions
     */
    public static function from(array $permissions): self
    {
        $total = \count($permissions);

        return new self($permissions, 1, max(1, $total), $total);
    }
}
