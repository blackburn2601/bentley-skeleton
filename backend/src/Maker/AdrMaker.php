<?php

declare(strict_types=1);

namespace App\Maker;

use Symfony\Bundle\MakerBundle\ConsoleStyle;
use Symfony\Bundle\MakerBundle\DependencyBuilder;
use Symfony\Bundle\MakerBundle\Generator;
use Symfony\Bundle\MakerBundle\InputConfiguration;
use Symfony\Bundle\MakerBundle\Maker\AbstractMaker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

/**
 * `bin/console make:adr "Use X instead of Y"`.
 *
 * ADRs get written when writing one is easier than not writing one. Numbering by hand,
 * remembering the MADR sections and copying the template are each small frictions, and
 * together they are why decision logs die. This removes all three.
 */
final class AdrMaker extends AbstractMaker
{
    public function __construct(private readonly string $projectDir)
    {
    }

    public static function getCommandName(): string
    {
        return 'make:adr';
    }

    public static function getCommandDescription(): string
    {
        return 'Create the next architecture decision record in docs/adr/';
    }

    public function configureCommand(Command $command, InputConfiguration $inputConfig): void
    {
        $command
            ->addArgument('title', InputArgument::OPTIONAL, 'Short imperative title, e.g. "Use Redis for the rate limiter"')
            ->setHelp(
                "Creates docs/adr/NNNN-slug.md from docs/adr/template.md with the next free number.\n"
                ."Fill in every section: an ADR without Consequences and Alternatives rejected is a\n"
                ."changelog entry, not a decision record.\n",
            );
    }

    public function configureDependencies(DependencyBuilder $dependencies): void
    {
    }

    public function generate(InputInterface $input, ConsoleStyle $io, Generator $generator): void
    {
        $title = $input->getArgument('title');
        if (!\is_string($title) || '' === trim($title)) {
            $answer = $io->ask('What decision is being recorded?');
            $title = \is_string($answer) ? $answer : '';
        }

        $title = trim($title);
        if ('' === $title) {
            $io->error('An ADR needs a title.');

            return;
        }

        $adrDir = $this->projectDir.'/../docs/adr';
        if (!is_dir($adrDir)) {
            $io->error(\sprintf('%s does not exist.', $adrDir));

            return;
        }

        $number = $this->nextNumber($adrDir);
        $slug = $this->slugify($title);
        $path = \sprintf('%s/%04d-%s.md', $adrDir, $number, $slug);

        $templatePath = $adrDir.'/template.md';
        if (!is_readable($templatePath)) {
            $io->error('docs/adr/template.md is missing — it is the canonical ADR shape.');

            return;
        }

        $template = file_get_contents($templatePath);
        if (false === $template) {
            $io->error(\sprintf('Could not read %s.', $templatePath));

            return;
        }

        $body = str_replace(
            ['{{NUMBER}}', '{{TITLE}}', '{{DATE}}'],
            [\sprintf('%04d', $number), $title, date('Y-m-d')],
            $template,
        );

        if (false === file_put_contents($path, $body)) {
            $io->error(\sprintf('Could not write %s.', $path));

            return;
        }

        $io->success(\sprintf('Created docs/adr/%04d-%s.md', $number, $slug));
        $io->text([
            'Next:',
            '  1. Fill in Context, Decision, Consequences, Alternatives rejected, Reversal cost.',
            '  2. Link the code that implements it.',
            '  3. Run `make docs` to refresh the ADR index.',
        ]);
    }

    private function nextNumber(string $adrDir): int
    {
        $highest = 0;

        foreach ((array) glob($adrDir.'/[0-9][0-9][0-9][0-9]-*.md') as $file) {
            if (!\is_string($file)) {
                continue;
            }

            $number = (int) substr(basename($file), 0, 4);
            $highest = max($highest, $number);
        }

        return $highest + 1;
    }

    private function slugify(string $title): string
    {
        $slug = strtolower(trim($title));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);

        return trim($slug, '-');
    }
}
