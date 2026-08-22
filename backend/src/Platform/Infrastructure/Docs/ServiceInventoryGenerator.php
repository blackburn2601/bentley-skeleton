<?php

declare(strict_types=1);

namespace App\Platform\Infrastructure\Docs;

use App\Platform\Application\DocumentGenerator;
use ReflectionClass;

/**
 * docs/SERVICES.md — every Application service and the one sentence it is responsible for.
 *
 * This is the document that makes INV-10 pay off. A contributor with no context asks "does
 * something already own this topic?", greps this file, and finds the answer — instead of
 * writing a second service that does half of what the first one does.
 */
final class ServiceInventoryGenerator implements DocumentGenerator
{
    public function __construct(private readonly string $projectDir)
    {
    }

    public function key(): string
    {
        return 'services';
    }

    public function path(): string
    {
        return 'docs/SERVICES.md';
    }

    public function generate(): string
    {
        $md = GeneratedFileHeader::for(
            'Services',
            'the @responsibility docblock on each class in src/*/Application/Service/',
        );

        $md .= "\nEvery Application service, grouped by bounded context.\n\n"
            ."**Before writing a new service, look for the topic here.** If a service already owns\n"
            ."it, extend that one. If none does, `make service` generates a conforming skeleton.\n";

        $byContext = $this->collect();

        if ([] === $byContext) {
            return $md."\n_No Application services yet._\n";
        }

        foreach ($byContext as $context => $services) {
            $md .= \sprintf("\n## %s\n\n| Service | Responsibility |\n|---|---|\n", $context);

            foreach ($services as $short => $responsibility) {
                $md .= \sprintf("| `%s` | %s |\n", $short, $responsibility);
            }
        }

        return $md;
    }

    /** @return array<string, array<string, string>> */
    private function collect(): array
    {
        $byContext = [];

        foreach ((array) glob($this->projectDir.'/src/*/Application/Service/*Service.php') as $file) {
            if (!\is_string($file)) {
                continue;
            }

            $context = basename(\dirname($file, 3));
            $short = basename($file, '.php');
            $fqcn = \sprintf('App\\%s\\Application\\Service\\%s', $context, $short);

            if (!class_exists($fqcn)) {
                continue;
            }

            $byContext[$context][$short] = $this->responsibilityOf($fqcn);
        }

        ksort($byContext);
        foreach ($byContext as &$services) {
            ksort($services);
        }

        return $byContext;
    }

    /** @param class-string $fqcn */
    private function responsibilityOf(string $fqcn): string
    {
        $doc = new ReflectionClass($fqcn)->getDocComment();

        if (false === $doc || 1 !== preg_match('/@responsibility\s+(.+?)(?:\n\s*\*\s*@|\n\s*\*\/)/s', $doc, $m)) {
            // PHPStan rejects a service without one, so reaching this means the file was
            // added without running the checks.
            return '**MISSING** — add a `@responsibility` docblock (INV-10)';
        }

        $sentence = (string) preg_replace('/\s*\n\s*\*\s*/', ' ', $m[1]);

        return trim($sentence);
    }
}
