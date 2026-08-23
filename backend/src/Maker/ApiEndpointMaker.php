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
use Symfony\Component\Console\Input\InputOption;

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
            // Every prompt below has a matching option, so the whole slice can be generated
            // without a TTY. An AI session, a script or CI has no way to answer a question,
            // and a maker they cannot drive is a maker they will work around by hand — which
            // is exactly how non-conforming endpoints get written.
            ->addOption('method', null, InputOption::VALUE_REQUIRED, 'HTTP method: GET, POST, PATCH, PUT or DELETE')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Route path, e.g. /api/v1/notes')
            ->addOption('permission', null, InputOption::VALUE_REQUIRED, 'Permission required, or PUBLIC_ACCESS')
            ->addOption('responsibility', null, InputOption::VALUE_REQUIRED, "The service's one-sentence @responsibility (INV-10)")
            ->setHelp(
                "Creates a full slice under src/Api/<Context>/ plus the Application service.\n"
                ."Follow docs/cookbook/add-endpoint.md; this maker is step 1 of that recipe.\n\n"
                ."Non-interactive:\n"
                ."  bin/console make:api-endpoint Account ListNotes \\\n"
                ."    --method=GET --path=/api/v1/notes --permission=note.read \\\n"
                ."    --responsibility='Lists the notes a user may read'\n",
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

        $methods = ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'];
        $method = strtoupper($this->option($input, 'method') ?? $this->choose($io, 'HTTP method', $methods));

        if (!\in_array($method, $methods, true)) {
            $io->error(\sprintf('Unknown HTTP method "%s". Use one of: %s.', $method, implode(', ', $methods)));

            return;
        }

        $path = $this->option($input, 'path') ?? $this->ask($io, 'Path', '/api/v1/'.strtolower($this->kebab($name)));

        $permission = $this->option($input, 'permission');

        if (null === $permission) {
            $io->text([
                '',
                'Which permission does this endpoint require?',
                'Use PUBLIC_ACCESS only if it is genuinely public — it is greppable for a reason.',
                'Object-level permissions look like "note.read"; declare them in PermissionCatalog.',
            ]);
            $permission = $this->ask($io, 'Permission', strtolower($this->kebab($context)).'.read');
        }

        $responsibility = $this->option($input, 'responsibility');

        if (null === $responsibility) {
            $io->text('');
            $responsibility = $this->ask($io, 'One sentence: what is this endpoint\'s service responsible for?');
        }

        $responsibility = rtrim(trim($responsibility), '.');
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
            // A GET carries its parameters in the query string, not a body. Binding one with
            // #[MapRequestPayload] yields an endpoint that silently ignores every filter it
            // was given, which is a bug the generator should not be able to produce.
            'payload_attribute' => 'GET' === $method ? 'MapQueryString' : 'MapRequestPayload',
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
            // PUBLIC_ACCESS is a Symfony sentinel, not a row in the catalogue. Telling someone
            // to declare it there sends them to sync-permissions, which will not fail — the
            // architecture test will, several steps later, about something else.
            'PUBLIC_ACCESS' === $permission
                ? '  4. Add this route to RoutesDeclarePermissionsTest::INTENTIONALLY_PUBLIC with '
                    .'the reason it is public — the suite fails until you do.'
                : \sprintf('  4. Declare "%s" in PermissionCatalog, then `bin/console app:acl:sync-permissions`.', $permission),
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

    private function option(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

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
