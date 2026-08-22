<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Application\Docs\DocumentGenerator;

/**
 * Test-only holder for the same tagged iterator `app:docs:generate` receives.
 *
 * Fetching the four generators individually would test four classes. Fetching the collection
 * also tests that each one is tagged — an untagged generator is never run, so its document
 * stops being regenerated while every gate stays green.
 */
final readonly class DocumentGenerators
{
    /** @var list<DocumentGenerator> */
    private array $generators;

    /** @param iterable<DocumentGenerator> $generators */
    public function __construct(iterable $generators)
    {
        $this->generators = array_values([...$generators]);
    }

    /** @return list<DocumentGenerator> */
    public function all(): array
    {
        return $this->generators;
    }
}
