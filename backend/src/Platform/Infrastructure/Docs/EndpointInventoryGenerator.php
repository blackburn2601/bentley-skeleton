<?php

declare(strict_types=1);

namespace App\Platform\Infrastructure\Docs;

use App\Platform\Application\DocumentGenerator;
use ReflectionClass;
use ReflectionNamedType;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * docs/ENDPOINTS.md — every route, with the permission it requires.
 *
 * Read from the real router rather than by scanning source, so what appears here is what is
 * actually reachable. An endpoint whose permission column reads MISSING is publicly
 * reachable — which is why that cell is loud.
 */
final class EndpointInventoryGenerator implements DocumentGenerator
{
    public function __construct(private readonly RouterInterface $router)
    {
    }

    public function key(): string
    {
        return 'endpoints';
    }

    public function path(): string
    {
        return 'docs/ENDPOINTS.md';
    }

    public function generate(): string
    {
        $md = GeneratedFileHeader::for('Endpoints', 'the compiled router plus controller attributes');

        $md .= "\nEvery application route, its required permission, and its request payload.\n\n"
            ."A **MISSING** permission means the endpoint is reachable without authorization. That is\n"
            ."a build failure (INV-11), so it should never appear here.\n\n"
            ."| Method | Path | Permission | Request DTO | Controller |\n|---|---|---|---|---|\n";

        $rows = $this->collect();

        foreach ($rows as $row) {
            $md .= \sprintf(
                "| %s | `%s` | %s | %s | `%s` |\n",
                $row['methods'],
                $row['path'],
                $row['permission'],
                $row['request'],
                $row['controller'],
            );
        }

        if ([] === $rows) {
            $md .= "| — | _No application endpoints yet._ | — | — | — |\n";
        }

        return $md;
    }

    /** @return list<array{methods: string, path: string, permission: string, request: string, controller: string}> */
    private function collect(): array
    {
        $rows = [];

        foreach ($this->router->getRouteCollection() as $route) {
            $controller = $route->getDefault('_controller');

            // Only our own one-action controllers; framework and bundle routes are noise here.
            if (!\is_string($controller) || !str_starts_with($controller, 'App\\Api\\')) {
                continue;
            }

            $class = str_contains($controller, '::') ? strstr($controller, '::', true) : $controller;
            if (!\is_string($class) || !class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            $methods = $route->getMethods();

            $rows[] = [
                'methods' => [] === $methods ? 'ANY' : implode(', ', $methods),
                'path' => $route->getPath(),
                'permission' => $this->permissionOf($reflection),
                'request' => $this->requestDtoOf($reflection),
                'controller' => $this->shortName($class),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['path'], $a['methods']] <=> [$b['path'], $b['methods']]);

        return $rows;
    }

    /** @param ReflectionClass<object> $reflection */
    private function permissionOf(ReflectionClass $reflection): string
    {
        $attributes = $reflection->getAttributes(IsGranted::class);

        if ($reflection->hasMethod('__invoke')) {
            $attributes = [...$attributes, ...$reflection->getMethod('__invoke')->getAttributes(IsGranted::class)];
        }

        foreach ($attributes as $attribute) {
            $arguments = $attribute->getArguments();
            $subject = $arguments['attribute'] ?? $arguments[0] ?? null;

            if (\is_string($subject)) {
                return 'PUBLIC_ACCESS' === $subject ? '_public_' : \sprintf('`%s`', $subject);
            }
        }

        return '**MISSING**';
    }

    /** @param ReflectionClass<object> $reflection */
    private function requestDtoOf(ReflectionClass $reflection): string
    {
        if (!$reflection->hasMethod('__invoke')) {
            return '—';
        }

        foreach ($reflection->getMethod('__invoke')->getParameters() as $parameter) {
            if ([] === $parameter->getAttributes(MapRequestPayload::class)) {
                continue;
            }

            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType) {
                return \sprintf('`%s`', $this->shortName($type->getName()));
            }
        }

        return '—';
    }

    private function shortName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');

        return false === $position ? $fqcn : substr($fqcn, $position + 1);
    }
}
