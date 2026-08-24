<?php

declare(strict_types=1);

namespace App\Tests\Integration\Docs;

use App\Acl\Domain\PermissionCatalog;
use App\Shared\Application\Docs\DocumentGenerator;
use App\Tests\Support\DocumentGenerators;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * The generated inventories are the entry point for a session with no prior context
 * (ADR-0016), so they are held to the same standard as code.
 *
 * The freshness gate (`make docs-check`) proves the committed files match the generators. It
 * cannot prove the generators are right — a generator that silently skipped half the services
 * would produce a stable, committed, wrong document, and the gate would stay green forever.
 * These tests check the generators against the thing they describe: the router, the
 * permission catalogue, the ADR directory, the service classes on disk.
 */
final class DocumentGeneratorTest extends KernelTestCase
{
    /** @var list<DocumentGenerator> */
    private array $generators;
    private string $projectDir;
    private string $srcDir;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->generators = $container->get(DocumentGenerators::class)->all();

        // The generators resolve docs/ as dirname(kernel.project_dir) — /app becomes /, so
        // the inventories are at /docs. compose.yaml mounts them there for exactly this.
        $this->projectDir = \dirname((string) $container->getParameter('kernel.project_dir'));
        $this->srcDir = $container->getParameter('kernel.project_dir').'/src';
    }

    public function testEveryGeneratorIsRegistered(): void
    {
        $keys = array_map(static fn (DocumentGenerator $g): string => $g->key(), $this->generators);
        sort($keys);

        self::assertSame(
            ['adr', 'endpoints', 'permissions', 'services'],
            $keys,
            'A generator that is not tagged is never run, and its document silently rots. '
            .'Adding one means adding it here too.',
        );
    }

    public function testEveryDocumentIsMarkedGeneratedAndEndsInANewline(): void
    {
        foreach ($this->generators as $generator) {
            $content = $generator->generate();

            self::assertStringStartsWith('<!--', $content, $generator->path().' must open with the do-not-edit banner.');
            self::assertStringContainsString(
                'app:docs:generate',
                $content,
                $generator->path().' must name the command that regenerates it, or someone will edit it by hand.',
            );
            self::assertStringEndsWith("\n", $content, $generator->path().' must end in a newline.');
            self::assertStringStartsWith('docs/', $generator->path());
        }
    }

    public function testTheKeysAreUniqueSoOnlyCanAddressOneDocument(): void
    {
        $keys = array_map(static fn (DocumentGenerator $g): string => $g->key(), $this->generators);

        self::assertSame(array_unique($keys), $keys, '--only=<key> would be ambiguous.');
    }

    public function testTheServiceInventoryListsEveryApplicationService(): void
    {
        $content = $this->generate('services');

        foreach ($this->serviceClassesOnDisk() as $short) {
            self::assertStringContainsString(
                '`'.$short.'`',
                $content,
                $short.' exists in src but is missing from docs/SERVICES.md. A contributor '
                .'looking for its topic will not find it, and will write a second service.',
            );
        }
    }

    public function testTheServiceInventoryCarriesTheResponsibilitySentence(): void
    {
        $content = $this->generate('services');

        // The sentence is the whole point of the file; a table of bare class names would
        // satisfy "lists every service" while being useless for finding a topic.
        self::assertMatchesRegularExpression(
            '/\| `CreateUserService` \| [A-Z][^|]{10,} \|/',
            $content,
            'Each row must pair the class with its @responsibility.',
        );
    }

    public function testTheEndpointInventoryListsEveryRoutedController(): void
    {
        $content = $this->generate('endpoints');
        $router = self::getContainer()->get(RouterInterface::class);

        foreach ($router->getRouteCollection() as $name => $route) {
            // Only this application's endpoints. /api/doc.json belongs to nelmio and is not
            // something a reader of ENDPOINTS.md is looking for.
            $controller = $route->getDefault('_controller');

            if (!\is_string($controller) || !str_starts_with($controller, 'App\\Api\\')) {
                continue;
            }

            self::assertStringContainsString(
                $route->getPath(),
                $content,
                \sprintf('Route %s (%s) is missing from docs/ENDPOINTS.md.', $name, $route->getPath()),
            );
        }
    }

    public function testTheEndpointInventoryRecordsThePermissionEachEndpointRequires(): void
    {
        $content = $this->generate('endpoints');

        // An endpoint list without permissions invites the reader to guess, and the guess is
        // always "probably authenticated".
        self::assertStringContainsString('_public_', $content, 'Public endpoints must be visible as public.');
        self::assertStringContainsString(PermissionCatalog::ACCOUNT_READ, $content);
    }

    public function testThePermissionInventoryListsEveryCatalogueEntry(): void
    {
        $content = $this->generate('permissions');

        foreach (PermissionCatalog::all() as $permission) {
            self::assertStringContainsString(
                $permission,
                $content,
                $permission.' is in the catalogue but absent from docs/PERMISSIONS.md.',
            );
        }
    }

    public function testTheAdrIndexListsEveryDecisionRecord(): void
    {
        $content = $this->generate('adr');
        $found = glob($this->projectDir.'/docs/adr/[0-9][0-9][0-9][0-9]-*.md');
        $found = false === $found ? [] : $found;

        self::assertNotEmpty($found, 'Precondition: there should be ADRs on disk.');

        foreach ($found as $path) {
            $basename = basename($path);

            self::assertStringContainsString(
                $basename,
                $content,
                $basename.' is not linked from the ADR index, so nothing points a reader at it.',
            );
        }
    }

    public function testTheAdrIndexLinksAreRelativeAndResolve(): void
    {
        $content = $this->generate('adr');

        preg_match_all('/\]\(([^)]+\.md)\)/', $content, $matches);
        self::assertNotEmpty($matches[1], 'The index must actually link to the records.');

        foreach ($matches[1] as $link) {
            self::assertFileExists(
                $this->projectDir.'/docs/adr/'.$link,
                'The ADR index links to '.$link.', which does not exist.',
            );
        }
    }

    public function testGeneratingTwiceProducesTheSameBytes(): void
    {
        foreach ($this->generators as $generator) {
            // Non-deterministic output — an unsorted directory read, an embedded timestamp —
            // makes the freshness gate fail at random, and the fix people reach for is to
            // delete the gate.
            self::assertSame(
                $generator->generate(),
                $generator->generate(),
                $generator->path().' is not deterministic.',
            );
        }
    }

    private function generate(string $key): string
    {
        foreach ($this->generators as $generator) {
            if ($generator->key() === $key) {
                return $generator->generate();
            }
        }

        self::fail('No generator with key '.$key);
    }

    /**
     * @return list<string>
     */
    private function serviceClassesOnDisk(): array
    {
        $found = glob($this->srcDir.'/*/Application/Service/*Service.php');
        $found = false === $found ? [] : $found;

        return array_map(static fn (string $p): string => basename($p, '.php'), $found);
    }
}
