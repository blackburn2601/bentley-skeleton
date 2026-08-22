<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The enforcement tooling must cover every context that actually exists.
 *
 * This is the failure mode none of the other checks can see. deptrac, PHPStan and Doctrine
 * are all configured by listing contexts explicitly. Add `src/Billing/` and forget to
 * register it, and every one of those tools reports success — while enforcing nothing at
 * all on the new code. A silently unenforced context is worse than no enforcement, because
 * the green pipeline says otherwise.
 *
 * So: discover the contexts from the filesystem, then assert the config files know about
 * each one.
 */
final class EnforcementCoverageTest extends TestCase
{
    private const array CONTEXT_EXEMPT = ['Api', 'Maker', 'Shared'];

    /** @return iterable<string, array{string}> */
    public static function contexts(): iterable
    {
        foreach (self::discoverContexts() as $context) {
            yield $context => [$context];
        }
    }

    public function testAtLeastOneContextExists(): void
    {
        self::assertNotEmpty(
            self::discoverContexts(),
            'No bounded contexts found under backend/src. Either the layout changed or this '
            .'test is looking in the wrong place — in both cases the coverage checks below '
            .'would vacuously pass.',
        );
    }

    #[DataProvider('contexts')]
    public function testContextHasADeptracLayer(string $context): void
    {
        $layers = $this->layerNames();

        self::assertContains($context, $layers, \sprintf(
            'Context "%s" exists under src/ but has no layer in deptrac-context.yaml, so the '
            .'bounded-context rules do not apply to it. Add a layer and a ruleset entry — see '
            .'docs/cookbook/add-entity-with-acl.md.',
            $context,
        ));
    }

    #[DataProvider('contexts')]
    public function testContextIsReachableOnlyThroughItsFacadeLayer(string $context): void
    {
        $layers = $this->layerNames();

        self::assertContains($context.'Facade', $layers, \sprintf(
            'Context "%s" has no "%sFacade" layer in deptrac-context.yaml. Without it, other '
            .'contexts have no legal way to call this one, and the first cross-context need '
            .'will be met by widening the ruleset instead.',
            $context,
            $context,
        ));
    }

    #[DataProvider('contexts')]
    public function testContextEntitiesAreMappedByDoctrine(string $context): void
    {
        $mappings = $this->section(self::backend('config/packages/doctrine.yaml'), 'doctrine', 'orm', 'mappings');

        self::assertArrayHasKey($context, $mappings, \sprintf(
            'Context "%s" has a Domain/ directory but no Doctrine mapping in '
            .'config/packages/doctrine.yaml. Its entities would be invisible to the ORM and '
            .'to migrations — auto_mapping is deliberately off (see that file).',
            $context,
        ));
    }

    /**
     * Layer names declared in the bounded-context ruleset.
     *
     * @return list<string>
     */
    private function layerNames(): array
    {
        $names = [];

        foreach ($this->section(self::backend('deptrac-context.yaml'), 'deptrac', 'layers') as $layer) {
            if (\is_array($layer) && \is_string($layer['name'] ?? null)) {
                $names[] = $layer['name'];
            }
        }

        return $names;
    }

    /**
     * Walk into a nested YAML section, failing with a useful message rather than a type error
     * if the file has been restructured.
     *
     * @return array<array-key, mixed>
     */
    private function section(string $file, string ...$path): array
    {
        $node = Yaml::parseFile($file);
        $walked = [];

        foreach ($path as $key) {
            $walked[] = $key;

            self::assertIsArray($node, \sprintf(
                '%s: expected "%s" to be a mapping. The enforcement config has been restructured, '
                .'so this coverage check can no longer verify it.',
                basename($file),
                implode('.', $walked),
            ));
            self::assertArrayHasKey($key, $node, \sprintf(
                '%s: missing the "%s" section.',
                basename($file),
                implode('.', $walked),
            ));

            $node = $node[$key];
        }

        self::assertIsArray($node, \sprintf('%s: "%s" is not a mapping.', basename($file), implode('.', $path)));

        return $node;
    }

    /** @return list<string> */
    private static function discoverContexts(): array
    {
        $contexts = [];

        foreach ((array) glob(self::backend('src/*'), \GLOB_ONLYDIR) as $dir) {
            if (!\is_string($dir)) {
                continue;
            }

            $name = basename($dir);
            if (\in_array($name, self::CONTEXT_EXEMPT, true)) {
                continue;
            }

            // A context is a directory that owns a Domain — that is what makes it a context
            // rather than a namespace someone happened to create.
            if (is_dir($dir.'/Domain')) {
                $contexts[] = $name;
            }
        }

        sort($contexts);

        return $contexts;
    }

    private static function backend(string $path): string
    {
        return \dirname(__DIR__, 2).'/'.$path;
    }
}
