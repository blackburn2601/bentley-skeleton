<?php

declare(strict_types=1);

namespace App\Acl\Application\Service;

use App\Acl\Domain\Permission;
use App\Acl\Domain\PermissionRepository;

/**
 * @responsibility Lists the permissions this application declares.
 *
 * Reads the database rows rather than PermissionCatalog. The catalog is the source of truth
 * for what *should* exist, but ACL entries reference rows — so if the two have drifted because
 * someone deployed without running app:acl:sync-permissions, this endpoint is where that
 * becomes visible instead of staying hidden.
 */
final readonly class ListPermissionsService
{
    public function __construct(private PermissionRepository $permissions)
    {
    }

    /**
     * @return list<array{id: string, name: string, resource: string, action: string}>
     */
    public function __invoke(): array
    {
        $items = array_map(static function (Permission $permission): array {
            // Names are "resource.action" by convention, which is what lets the UI group them.
            [$resource, $action] = array_pad(explode('.', $permission->name(), 2), 2, '');

            return [
                'id' => $permission->id()->toRfc4122(),
                'name' => $permission->name(),
                'resource' => $resource,
                'action' => $action,
            ];
        }, $this->permissions->findAll());

        usort($items, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $items;
    }
}
