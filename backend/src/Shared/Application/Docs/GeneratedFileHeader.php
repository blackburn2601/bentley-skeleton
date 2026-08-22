<?php

declare(strict_types=1);

namespace App\Shared\Application\Docs;

/**
 * The banner every generated document carries.
 *
 * It exists because the failure mode for generated docs is someone editing them by hand:
 * the edit survives until the next `make docs`, then vanishes, and the person concludes the
 * tooling is broken rather than that the file was never theirs to edit.
 */
final class GeneratedFileHeader
{
    public static function for(string $title, string $sourceOfTruth): string
    {
        return <<<MD
            <!-- GENERATED FILE — DO NOT EDIT.
                 Produced by `bin/console app:docs:generate` (make docs).
                 Source of truth: {$sourceOfTruth}
                 CI fails on any diff between this file and a fresh run (ADR-0016). -->

            # {$title}

            MD;
    }
}
