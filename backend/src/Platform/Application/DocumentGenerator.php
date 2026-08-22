<?php

declare(strict_types=1);

namespace App\Platform\Application;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A generated documentation file.
 *
 * A real port boundary with four implementations, which is the bar INV-12 sets for
 * introducing an interface at all.
 */
#[AutoconfigureTag]
interface DocumentGenerator
{
    /** Repository-relative path, e.g. "docs/SERVICES.md". */
    public function path(): string;

    /** The complete file content, ending in a newline. */
    public function generate(): string;

    /** Short key for `app:docs:generate --only=<key>`. */
    public function key(): string;
}
