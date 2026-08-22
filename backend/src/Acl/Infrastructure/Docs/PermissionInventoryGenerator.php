<?php

declare(strict_types=1);

namespace App\Acl\Infrastructure\Docs;

use App\Acl\Domain\PermissionCatalog;
use App\Shared\Application\Docs\DocumentGenerator;
use App\Shared\Application\Docs\GeneratedFileHeader;
use ReflectionClass;

/**
 * docs/PERMISSIONS.md — the code-declared permission catalog.
 *
 * Permissions are constants rather than rows so that adding one is a reviewable diff and
 * survives a redeploy. This document is what that buys: a readable list of what exists,
 * derived from the same constants the endpoints reference.
 */
final class PermissionInventoryGenerator implements DocumentGenerator
{
    /**
     * Referenced by name rather than by `::class`: the Acl context declares it, and this
     * generator must still run (reporting "not yet declared") before that context exists.
     */
    private const string CATALOG = PermissionCatalog::class;

    public function key(): string
    {
        return 'permissions';
    }

    public function path(): string
    {
        return 'docs/PERMISSIONS.md';
    }

    public function generate(): string
    {
        $md = $this->preamble();

        $grouped = $this->groupByResource(self::CATALOG);

        if ([] === $grouped) {
            return $md."\n_No permissions declared yet._\n";
        }

        foreach ($grouped as $resource => $permissions) {
            $md .= \sprintf("\n## %s\n\n| Permission | Constant |\n|---|---|\n", $resource);

            foreach ($permissions as $value => $name) {
                $md .= \sprintf("| `%s` | `PermissionCatalog::%s` |\n", $value, $name);
            }
        }

        return $md;
    }

    private function preamble(): string
    {
        return GeneratedFileHeader::for('Permissions', 'the constants on '.self::CATALOG)
            ."\nEvery permission this application knows about, grouped by resource.\n\n"
            ."Declared in code, synced into the database with `bin/console app:acl:sync-permissions`.\n"
            ."**Never insert a permission row by hand** — it would exist in one environment and not\n"
            ."another, with nothing to tell you. See [cookbook/add-permission.md](cookbook/add-permission.md).\n";
    }

    /**
     * @param class-string $catalog
     *
     * @return array<string, array<string, string>>
     */
    private function groupByResource(string $catalog): array
    {
        $grouped = [];

        foreach ((new ReflectionClass($catalog))->getConstants() as $name => $value) {
            if (!\is_string($value)) {
                continue;
            }

            $resource = str_contains($value, '.') ? strstr($value, '.', true) : 'general';
            $grouped[(string) $resource][$value] = $name;
        }

        ksort($grouped);
        foreach ($grouped as &$permissions) {
            ksort($permissions);
        }

        return $grouped;
    }
}
