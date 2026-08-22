<?php

declare(strict_types=1);

namespace App\Platform\Infrastructure\Console;

use App\Platform\Application\DocumentGenerator;
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
        $check = true === $input->getOption('check');

        $repoRoot = \dirname($this->projectDir);
        $written = 0;
        $stale = [];
        $matched = false;

        foreach ($this->generators as $generator) {
            if (null !== $only && $generator->key() !== $only) {
                continue;
            }

            $matched = true;
            $path = $repoRoot.'/'.$generator->path();
            $content = $generator->generate();

            $current = is_readable($path) ? file_get_contents($path) : null;

            if ($current === $content) {
                $io->writeln(\sprintf('  <fg=gray>unchanged</>  %s', $generator->path()));

                continue;
            }

            if ($check) {
                $stale[] = $generator->path();

                continue;
            }

            if (false === file_put_contents($path, $content)) {
                $io->error(\sprintf('Could not write %s', $generator->path()));

                return Command::FAILURE;
            }

            ++$written;
            $io->writeln(\sprintf('  <info>written</>    %s', $generator->path()));
        }

        if (null !== $only && !$matched) {
            $io->error(\sprintf('No generator named "%s".', $only));

            return Command::FAILURE;
        }

        if ([] !== $stale) {
            $io->error('These generated documents are stale: '.implode(', ', $stale));
            $io->writeln('Run `make docs` and commit the result.');

            return Command::FAILURE;
        }

        $io->success(0 === $written ? 'Documentation already up to date.' : \sprintf('%d document(s) regenerated.', $written));

        return Command::SUCCESS;
    }
}
