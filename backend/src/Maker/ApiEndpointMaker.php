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
 * `bin/console make:api-endpoint`.
 *
 * Generates a complete vertical slice — controller, request DTO, response view, service,
 * and a functional test that already asserts the endpoint is not publicly reachable.
 *
 * This is the maker that makes the architecture self-enforcing. The rules in
 * docs/INVARIANTS.md describe a shape; producing that shape by hand takes five files and
 * a permission decision, which is exactly the moment someone takes a shortcut. Generating
 * it means the conforming path is also the fastest one.
 */
final class ApiEndpointMaker extends AbstractMaker
{
    public function __construct(private readonly string $projectDir)
    {
    }

    public static function getCommandName(): string
    {
        return 'make:api-endpoint';
    }

    public static function getCommandDescription(): string
    {
        return 'Create a conforming API endpoint slice: controller, DTOs, service and test';
    }

    public function configureCommand(Command $command, InputConfiguration $inputConfig): void
    {
        $command
            ->addArgument('context', InputArgument::OPTIONAL, 'Bounded context that owns this endpoint')
            ->addArgument('name', InputArgument::OPTIONAL, 'Endpoint name, e.g. ListNotes')
            ->setHelp(
                "Creates a full slice under src/Api/<Context>/ plus the Application service.\n"
                ."Follow docs/cookbook/add-endpoint.md; this maker is step 1 of that recipe.\n",
            );
    }

    public function configureDependencies(DependencyBuilder $dependencies): void
    {
    }

    public function generate(InputInterface $input, ConsoleStyle $io, Generator $generator): void
    {
        $contexts = $this->availableContexts();

        $context = $this->argument($input, 'context') ?? $this->choose($io, 'Which bounded context owns this endpoint?', $contexts);
        $context = ucfirst(trim($context));

        if (!\in_array($context, $contexts, true)) {
            $io->error(\sprintf('Unknown context "%s". Existing: %s.', $context, implode(', ', $contexts)));

            return;
        }

        $name = $this->argument($input, 'name') ?? $this->ask($io, 'Endpoint name (e.g. ListNotes)');
        $name = trim($name);
        if ('' === $name) {
            $io->error('An endpoint needs a name.');

            return;
        }

        $method = strtoupper($this->choose($io, 'HTTP method', ['GET', 'POST', 'PATCH', 'PUT', 'DELETE']));
        $path = $this->ask($io, 'Path', '/api/v1/'.strtolower($this->kebab($name)));

        $io->text([
            '',
            'Which permission does this endpoint require?',
            'Use PUBLIC_ACCESS only if it is genuinely public — it is greppable for a reason.',
            'Object-level permissions look like "note.read"; declare them in PermissionCatalog.',
        ]);
        $permission = $this->ask($io, 'Permission', strtolower($this->kebab($context)).'.read');

        $io->text('');
        $responsibility = rtrim(trim($this->ask($io, 'One sentence: what is this endpoint\'s service responsible for?')), '.');
        if ('' === $responsibility) {
            $io->error('The responsibility sentence is mandatory (INV-10).');

            return;
        }

        $apiNs = \sprintf('App\\Api\\%s', $context);
        $controller = \sprintf('%s\\%sController', $apiNs, $name);
        $request = \sprintf('%s\\Request\\%sRequest', $apiNs, $name);
        $response = \sprintf('%s\\Response\\%sResponse', $apiNs, $name);
        $service = \sprintf('App\\%s\\Application\\Service\\%sService', $context, $name);
        $test = \sprintf('App\\Tests\\Functional\\%s\\%sControllerTest', $context, $name);

        $shared = [
            'http_method' => $method,
            'path' => $path,
            'permission' => $permission,
        ];

        $generator->generateClass($request, $this->skeleton('RequestDto.tpl.php'), $shared);
        $generator->generateClass($response, $this->skeleton('ResponseView.tpl.php'), $shared);
        $generator->generateClass($service, $this->skeleton('EndpointService.tpl.php'), [
            'responsibility' => $responsibility,
        ]);
        $generator->generateClass($controller, $this->skeleton('Controller.tpl.php'), $shared + [
            'route_name' => $this->routeName($method, $name),
            'request_fqcn' => $request,
            'request_short' => $name.'Request',
            'response_fqcn' => $response,
            'response_short' => $name.'Response',
            'service_fqcn' => $service,
            'service_short' => $name.'Service',
            'service_var' => lcfirst($name).'Service',
        ]);
        $generator->generateClass($test, $this->skeleton('EndpointTest.tpl.php'), $shared);

        $generator->writeChanges();

        $this->writeSuccessMessage($io);
        $io->listing([
            $controller,
            $request,
            $response,
            $service,
            $test,
        ]);
        $io->text([
            'Next, in order:',
            \sprintf('  1. Implement %sService — it owns the logic; the controller must stay thin.', $name),
            \sprintf('  2. Fill in the %sRequest fields and their constraints.', $name),
            \sprintf('  3. Map the result in %sResponse::from().', $name),
            \sprintf('  4. Declare "%s" in PermissionCatalog, then `bin/console app:acl:sync-permissions`.', $permission),
            '  5. Finish the functional test — the two incomplete cases are the ones that catch IDOR.',
            '  6. `make check` — deptrac, PHPStan and PHPMD will reject the slice if it drifted.',
        ]);
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

    private function routeName(string $method, string $name): string
    {
        return strtolower($method).'_'.str_replace('-', '_', $this->kebab($name));
    }

    private function kebab(string $value): string
    {
        $kebab = preg_replace('/(?<!^)[A-Z]/', '-$0', $value) ?? $value;

        return strtolower($kebab);
    }

    private function argument(InputInterface $input, string $name): ?string
    {
        $value = $input->getArgument($name);

        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }

    private function ask(ConsoleStyle $io, string $question, ?string $default = null): string
    {
        $answer = $io->ask($question, $default);

        return \is_string($answer) ? $answer : '';
    }

    /** @param list<string> $choices */
    private function choose(ConsoleStyle $io, string $question, array $choices): string
    {
        $answer = $io->choice($question, $choices);

        return \is_string($answer) ? $answer : '';
    }

    private function skeleton(string $file): string
    {
        return __DIR__.'/skeleton/'.$file;
    }
}
