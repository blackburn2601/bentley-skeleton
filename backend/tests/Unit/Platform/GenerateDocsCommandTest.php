<?php

declare(strict_types=1);

namespace App\Tests\Unit\Platform;

use App\Platform\Infrastructure\Console\GenerateDocsCommand;
use App\Shared\Application\Docs\DocumentGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command behind `make docs` and `make docs-check`.
 *
 * Driven with stub generators against a temporary directory, so the write path is exercised
 * for real without the test needing the repository's own docs/ — and without a bug here being
 * able to rewrite them.
 *
 * `--check` is the CI gate. The case that matters is the one where it is asked about a file
 * that is out of date: it must report failure and name the file, and it must not quietly fix
 * it, because a gate that repairs the thing it is checking always passes.
 */
final class GenerateDocsCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/docs-cmd-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/backend', 0o777, true);
        mkdir($this->root.'/docs', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->root.'/docs/*.md') as $file) {
            unlink((string) $file);
        }

        foreach ([$this->root.'/docs', $this->root.'/backend', $this->root] as $dir) {
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }

    public function testItWritesADocumentThatDoesNotExistYet(): void
    {
        $tester = $this->runCommand([$this->generator('alpha', 'docs/ALPHA.md', "# Alpha\n")]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringEqualsFile($this->root.'/docs/ALPHA.md', "# Alpha\n");
        self::assertStringContainsString('written', $tester->getDisplay());
    }

    public function testItLeavesAnUpToDateDocumentAloneAndSaysSo(): void
    {
        file_put_contents($this->root.'/docs/ALPHA.md', "# Alpha\n");

        $tester = $this->runCommand([$this->generator('alpha', 'docs/ALPHA.md', "# Alpha\n")]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('unchanged', $tester->getDisplay());
    }

    public function testItRewritesADocumentThatHasDrifted(): void
    {
        file_put_contents($this->root.'/docs/ALPHA.md', "# Stale\n");

        $tester = $this->runCommand([$this->generator('alpha', 'docs/ALPHA.md', "# Alpha\n")]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringEqualsFile($this->root.'/docs/ALPHA.md', "# Alpha\n");
    }

    public function testCheckFailsOnAStaleDocumentAndNamesIt(): void
    {
        file_put_contents($this->root.'/docs/ALPHA.md', "# Stale\n");

        $tester = $this->runCommand([$this->generator('alpha', 'docs/ALPHA.md', "# Alpha\n")], ['--check' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('docs/ALPHA.md', $tester->getDisplay());
    }

    public function testCheckDoesNotWriteWhatItFoundStale(): void
    {
        file_put_contents($this->root.'/docs/ALPHA.md', "# Stale\n");

        $this->runCommand([$this->generator('alpha', 'docs/ALPHA.md', "# Alpha\n")], ['--check' => true]);

        // A gate that repairs what it is checking reports success on the next run and never
        // fails again — the drift is real, and nobody is told.
        self::assertStringEqualsFile($this->root.'/docs/ALPHA.md', "# Stale\n");
    }

    public function testCheckPassesWhenEverythingIsCurrent(): void
    {
        file_put_contents($this->root.'/docs/ALPHA.md', "# Alpha\n");

        $tester = $this->runCommand([$this->generator('alpha', 'docs/ALPHA.md', "# Alpha\n")], ['--check' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testOnlyRestrictsGenerationToOneDocument(): void
    {
        $tester = $this->runCommand(
            [
                $this->generator('alpha', 'docs/ALPHA.md', "# Alpha\n"),
                $this->generator('beta', 'docs/BETA.md', "# Beta\n"),
            ],
            ['--only' => 'alpha'],
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileExists($this->root.'/docs/ALPHA.md');
        self::assertFileDoesNotExist($this->root.'/docs/BETA.md', '--only=alpha must not touch the other documents.');
    }

    public function testAnUnknownOnlyKeyIsRejectedRatherThanSilentlyGeneratingNothing(): void
    {
        $tester = $this->runCommand(
            [$this->generator('alpha', 'docs/ALPHA.md', "# Alpha\n")],
            ['--only' => 'nonsense'],
        );

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertFileDoesNotExist($this->root.'/docs/ALPHA.md');
    }

    /**
     * @param list<DocumentGenerator> $generators
     * @param array<string, mixed>    $input
     */
    private function runCommand(array $generators, array $input = []): CommandTester
    {
        $tester = new CommandTester(new GenerateDocsCommand($generators, $this->root.'/backend'));
        $tester->execute($input);

        return $tester;
    }

    private function generator(string $key, string $path, string $content): DocumentGenerator
    {
        return new readonly class($key, $path, $content) implements DocumentGenerator {
            public function __construct(
                private string $key,
                private string $path,
                private string $content,
            ) {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function path(): string
            {
                return $this->path;
            }

            public function generate(): string
            {
                return $this->content;
            }
        };
    }
}
