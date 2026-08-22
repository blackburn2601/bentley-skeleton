<?php

declare(strict_types=1);

namespace App\Acl\Application\Service;

use App\Acl\Domain\Permission;
use App\Acl\Domain\PermissionCatalog;
use App\Acl\Domain\PermissionRepository;
use App\Shared\Domain\Clock;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @responsibility Reconciles the database permission rows with the code-declared catalog.
 */
final readonly class SyncPermissionsService
{
    public function __construct(
        private PermissionRepository $permissions,
        private EntityManagerInterface $em,
        private Clock $clock,
    ) {
    }

    /**
     * Insert anything the catalog declares that the database lacks, and report the reverse.
     *
     * Deliberately never deletes. A permission row that is no longer in the catalog may still
     * have grants pointing at it, and silently removing it would revoke access as a side
     * effect of a code change — with the ON DELETE CASCADE on `acl_entry` taking the grants
     * with it. Removing a permission is a decision, so it gets reported and left alone.
     *
     * @return array{added: list<string>, orphaned: list<string>}
     */
    public function __invoke(): array
    {
        $declared = PermissionCatalog::all();
        $existing = $this->permissions->findAllNames();

        $added = [];
        $now = $this->clock->now();

        foreach (array_diff($declared, $existing) as $name) {
            $this->permissions->save(new Permission($name, $now));
            $added[] = $name;
        }

        $this->em->flush();

        return [
            'added' => $added,
            'orphaned' => array_values(array_diff($existing, $declared)),
        ];
    }
}
