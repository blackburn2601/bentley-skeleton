<?php

declare(strict_types=1);

namespace App\Platform\Infrastructure\Console;

use App\Shared\Application\Docs\DocumentGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * `bin/console app:docs:generate` — rewrite the generated inventories.
 *
 * `make docs` runs this and then fails on any git diff, so the documents cannot drift from
 * the code (ADR-0016). The failure is deliberately noisy: stale documentation is worse than
 * none, because it gets trusted.
 */
#[AsCommand(
    name: 'app:docs:generate',
    description: 'Regenerate docs/SERVICES.md, ENDPOINTS.md, PERMISSIONS.md and adr/README.md',
)]
final class GenerateDocsCommand extends Command
{
    private const string UNCHANGED = 'unchanged';
    private const string WRITTEN = 'written';
    private const string STALE = 'stale';
    private const string FAILED = 'failed';

    /**
     * @param iterable<DocumentGenerator> $generators
     */
    public function __construct(
        #[AutowireIterator(DocumentGenerator::class)]
        private readonly iterable $generators,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Generate just one document (services, endpoints, permissions, adr)')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Report what would change without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $only = $input->getOption('only');
        $only = \is_string($only) ? $only : null;

        $selected = $this->select($only);

        if ([] === $selected) {
            $io->error(\sprintf('No generator named "%s".', (string) $only));

            return Command::FAILURE;
        }

        return $this->generateAll($io, $selected, true === $input->getOption('check'));
    }

    /**
     * @param list<DocumentGenerator> $generators
     */
    private function generateAll(SymfonyStyle $io, array $generators, bool $check): int
    {
        $written = 0;
        $stale = [];

        foreach ($generators as $generator) {
            $outcome = $this->reconcile($generator, $check);

            if (self::FAILED === $outcome) {
                $io->error(\sprintf('Could not write %s', $generator->path()));

                return Command::FAILURE;
            }

            $this->report($io, $generator, $outcome);

            $written += self::WRITTEN === $outcome ? 1 : 0;

            if (self::STALE === $outcome) {
                $stale[] = $generator->path();
            }
        }

        if ([] !== $stale) {
            $io->error('These generated documents are stale: '.implode(', ', $stale));
            $io->writeln('Run `make docs` and commit the result.');

            return Command::FAILURE;
        }

        $io->success(0 === $written
            ? 'Documentation already up to date.'
            : \sprintf('%d document(s) regenerated.', $written));

        return Command::SUCCESS;
    }

    private function report(SymfonyStyle $io, DocumentGenerator $generator, string $outcome): void
    {
        match ($outcome) {
            self::UNCHANGED => $io->writeln(\sprintf('  <fg=gray>unchanged</>  %s', $generator->path())),
            self::WRITTEN => $io->writeln(\sprintf('  <info>written</>    %s', $generator->path())),
            default => null,
        };
    }

    /**
     * @return list<DocumentGenerator>
     */
    private function select(?string $only): array
    {
        $selected = [];

        foreach ($this->generators as $generator) {
            if (null === $only || $generator->key() === $only) {
                $selected[] = $generator;
            }
        }

        return $selected;
    }

    /**
     * Bring one document in line with the code, or report that it is not.
     *
     * @return self::UNCHANGED|self::WRITTEN|self::STALE|self::FAILED
     */
    private function reconcile(DocumentGenerator $generator, bool $check): string
    {
        $path = \dirname($this->projectDir).'/'.$generator->path();
        $content = $generator->generate();
        $current = is_readable($path) ? file_get_contents($path) : null;

        if ($current === $content) {
            return self::UNCHANGED;
        }

        if ($check) {
            return self::STALE;
        }

        return false === file_put_contents($path, $content) ? self::FAILED : self::WRITTEN;
    }
}
