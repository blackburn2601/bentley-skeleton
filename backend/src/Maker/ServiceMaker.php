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
 * `bin/console make:service`.
 *
 * Generates an Application service that already satisfies every rule in
 * docs/INVARIANTS.md: final readonly, constructor injection, one `@responsibility`
 * sentence, plus the unit test that will hold it to that sentence.
 *
 * The prompt for the responsibility is the point of this maker. Being asked to describe
 * the class in one sentence BEFORE writing it is what stops the second topic being added
 * later — by which time the class has a name that no longer describes it.
 */
final class ServiceMaker extends AbstractMaker
{
    public function __construct(private readonly string $projectDir)
    {
    }

    public static function getCommandName(): string
    {
        return 'make:service';
    }

    public static function getCommandDescription(): string
    {
        return 'Create a single-topic Application service and its unit test';
    }

    public function configureCommand(Command $command, InputConfiguration $inputConfig): void
    {
        $command
            ->addArgument('context', InputArgument::OPTIONAL, 'Bounded context (Account, Acl, Audit, Platform, …)')
            ->addArgument('name', InputArgument::OPTIONAL, 'Service name without the Service suffix, e.g. RotateRefreshToken')
            ->setHelp("Creates src/<Context>/Application/Service/<Name>Service.php and its unit test.\n");
    }

    public function configureDependencies(DependencyBuilder $dependencies): void
    {
    }

    public function generate(InputInterface $input, ConsoleStyle $io, Generator $generator): void
    {
        $context = $this->askContext($input, $io);
        if (null === $context) {
            return;
        }

        $name = $this->stringArgument($input, 'name') ?? $this->askString($io, 'Service name (without the Service suffix)');
        $name = preg_replace('/Service$/', '', trim($name)) ?? '';
        if ('' === $name) {
            $io->error('A service needs a name.');

            return;
        }

        $io->text([
            '',
            'Describe this service in ONE sentence, with no "and".',
            'If you need "and", it is two services — see INV-10. This becomes the',
            '@responsibility docblock and the row in docs/SERVICES.md.',
        ]);
        $responsibility = rtrim(trim($this->askString($io, 'Responsibility')), '.');

        if ('' === $responsibility) {
            $io->error('The responsibility sentence is mandatory; PHPStan rejects a service without it.');

            return;
        }

        if (1 === preg_match('/\b(and|plus)\b/i', $responsibility)) {
            $io->warning('That sentence contains a conjunction, so the build will reject it as two topics.');

            if (!$io->confirm('Generate it anyway?', false)) {
                return;
            }
        }

        $serviceFqcn = \sprintf('App\\%s\\Application\\Service\\%sService', $context, $name);
        $testFqcn = \sprintf('App\\Tests\\Unit\\%s\\%sServiceTest', $context, $name);

        $generator->generateClass($serviceFqcn, $this->skeleton('Service.tpl.php'), [
            'responsibility' => $responsibility,
        ]);
        $generator->generateClass($testFqcn, $this->skeleton('ServiceTest.tpl.php'), [
            'service_fqcn' => $serviceFqcn,
            'service_short' => $name.'Service',
        ]);

        $generator->writeChanges();

        $this->writeSuccessMessage($io);
        $io->text([
            \sprintf('  <info>%s</info>', $serviceFqcn),
            \sprintf('  <info>%s</info>', $testFqcn),
            '',
            'Next: implement it, then `make stan test docs`. `make docs` adds it to docs/SERVICES.md,',
            'and CI fails if you forget — that inventory is how the next contributor finds this class.',
        ]);
    }

    private function askContext(InputInterface $input, ConsoleStyle $io): ?string
    {
        $available = $this->availableContexts();

        $context = $this->stringArgument($input, 'context');
        if (null === $context) {
            $choice = $io->choice('Which bounded context?', $available);
            $context = \is_string($choice) ? $choice : '';
        }

        $context = ucfirst(trim($context));

        if (!\in_array($context, $available, true)) {
            $io->error(\sprintf(
                'Unknown context "%s". Existing contexts: %s. Creating a new one is an architecture '
                .'change: add it to deptrac-context.yaml and doctrine.yaml first, or '
                .'EnforcementCoverageTest will fail (by design).',
                $context,
                implode(', ', $available),
            ));

            return null;
        }

        return $context;
    }

    /** @return list<string> */
    private function availableContexts(): array
    {
        $contexts = [];

        foreach ((array) glob($this->projectDir.'/src/*', \GLOB_ONLYDIR) as $dir) {
            if (\is_string($dir) && is_dir($dir.'/Application/Service')) {
                $contexts[] = basename($dir);
            }
        }

        sort($contexts);

        return $contexts;
    }

    private function askString(ConsoleStyle $io, string $question): string
    {
        $answer = $io->ask($question);

        return \is_string($answer) ? $answer : '';
    }

    private function stringArgument(InputInterface $input, string $name): ?string
    {
        $value = $input->getArgument($name);

        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }

    private function skeleton(string $file): string
    {
        return __DIR__.'/skeleton/'.$file;
    }
}
