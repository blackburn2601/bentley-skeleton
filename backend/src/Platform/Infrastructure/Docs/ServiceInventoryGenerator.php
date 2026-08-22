<?php

declare(strict_types=1);

namespace App\Platform\Infrastructure\Docs;

use App\Shared\Application\Docs\DocumentGenerator;
use App\Shared\Application\Docs\GeneratedFileHeader;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * docs/SERVICES.md — every Application service and the one sentence it is responsible for.
 *
 * This is the document that makes INV-10 pay off. A contributor with no context asks "does
 * something already own this topic?", greps this file, and finds the answer — instead of
 * writing a second service that does half of what the first one does.
 */
final readonly class ServiceInventoryGenerator implements DocumentGenerator
{
    private const string TAG = '@responsibility';

    public function __construct(private string $projectDir)
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

    /**
     * Every Application class carrying a `@responsibility`, at any depth.
     *
     * Not just `Application/Service/*Service.php`: the classes a newcomer most needs to find —
     * PermissionResolver, AclFacade — do not live there, and an inventory that omits them is
     * worse than useless, because it looks complete.
     *
     * Discovery is by docblock rather than by naming convention, so the inventory and the
     * PHPStan rule agree on what a service is by construction.
     *
     * @return array<string, array<string, string>>
     */
    private function collect(): array
    {
        $byContext = [];

        foreach ($this->applicationFiles() as $file) {
            $fqcn = $this->classNameFor($file);

            if (!class_exists($fqcn)) {
                continue;
            }

            $responsibility = $this->responsibilityOf($fqcn);

            if (null === $responsibility) {
                continue;
            }

            $byContext[$this->contextOf($file)][$this->shortName($fqcn)] = $responsibility;
        }

        ksort($byContext);
        foreach ($byContext as &$services) {
            ksort($services);
        }

        return $byContext;
    }

    /** @return list<string> */
    private function applicationFiles(): array
    {
        $files = [];

        foreach ((array) glob($this->projectDir.'/src/*/Application', \GLOB_ONLYDIR) as $dir) {
            if (!\is_string($dir)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $entry) {
                if ($entry instanceof SplFileInfo && 'php' === $entry->getExtension()) {
                    $files[] = $entry->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    private function contextOf(string $file): string
    {
        $relative = str_replace($this->projectDir.'/src/', '', $file);
        $context = strstr($relative, \DIRECTORY_SEPARATOR, true);

        return \is_string($context) ? $context : $relative;
    }

    /** @return class-string */
    private function classNameFor(string $file): string
    {
        $relative = str_replace([$this->projectDir.'/src/', '.php'], '', $file);

        /** @var class-string $fqcn */
        $fqcn = 'App\\'.str_replace(\DIRECTORY_SEPARATOR, '\\', $relative);

        return $fqcn;
    }

    private function shortName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');

        return false === $position ? $fqcn : substr($fqcn, $position + 1);
    }

    /**
     * The responsibility sentence, or null if this class does not claim one.
     *
     * Null means "not a service" — a value object, a port interface, a formatter — and is
     * skipped rather than reported as missing. Whether a class *ought* to declare one is the
     * PHPStan rule's judgement; duplicating it here would let the two disagree.
     *
     * @param class-string $fqcn
     */
    private function responsibilityOf(string $fqcn): ?string
    {
        $reflection = new ReflectionClass($fqcn);

        if ($reflection->isInterface() || $reflection->isAbstract() || $reflection->isEnum()) {
            return null;
        }

        $doc = $reflection->getDocComment();

        if (false === $doc || !str_contains($doc, self::TAG)) {
            return null;
        }

        $sentence = $this->firstParagraphAfterTag($doc);

        return '' === $sentence ? null : $sentence;
    }

    /**
     * The first paragraph following `@responsibility`.
     *
     * Line-walking rather than a regular expression, and deliberately the same algorithm as
     * ServiceMustDeclareResponsibilityRule: a pattern that stops only at the next `@` tag
     * swallows the entire explanatory docblock, which is exactly what a one-line inventory
     * must not contain. The sentence ends at the first blank docblock line.
     */
    private function firstParagraphAfterTag(string $doc): string
    {
        $lines = preg_split('/\R/', $doc);

        if (false === $lines) {
            return '';
        }

        $collected = [];
        $collecting = false;

        foreach ($lines as $line) {
            $text = trim(ltrim(trim($line), '/*'));

            if ($collecting) {
                if ('' === $text || str_starts_with($text, '@')) {
                    break;
                }

                $collected[] = $text;

                continue;
            }

            if (str_starts_with($text, self::TAG)) {
                $collecting = true;
                $collected[] = trim(substr($text, \strlen(self::TAG)));
            }
        }

        return trim(implode(' ', array_filter($collected, static fn (string $part): bool => '' !== $part)));
    }
}
