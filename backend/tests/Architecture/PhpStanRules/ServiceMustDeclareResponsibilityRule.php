<?php

declare(strict_types=1);

namespace App\Tests\Architecture\PhpStanRules;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\UnionType;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @responsibility Enforces that every Application service declares exactly one semantic topic.
 *
 * INV-10, and the rule this whole skeleton is built around. Every Application service
 * carries a one-sentence `@responsibility` docblock. Two things then become true:
 *
 *  1. `docs/SERVICES.md` can be generated from the codebase, so an agent with no context
 *     can find the service that already owns a topic instead of writing a second one.
 *  2. "One topic" stops being a matter of taste. If the sentence needs an "and", the class
 *     is doing two things and belongs as two classes.
 *
 * The conjunction check is deliberately crude. It is not trying to understand English; it
 * is making the author notice the moment they reach for a conjunction.
 *
 * @implements Rule<InClassNode>
 */
final class ServiceMustDeclareResponsibilityRule implements Rule
{
    private const string TAG = '@responsibility';

    /** Conjunctions that betray a second topic. Matched case-insensitively, word-bounded. */
    private const array CONJUNCTIONS = [' and ', ' & ', '; ', ' plus ', ' as well as ', ' also '];

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!Layer::isApplicationClass($scope->getFile())) {
            return [];
        }

        $class = $node->getClassReflection();

        // Interfaces, enums and abstracts describe a contract rather than owning a topic.
        if ($class->isInterface() || $class->isEnum() || $class->isAbstract()) {
            return [];
        }

        $original = $node->getOriginalNode();

        if (!$original instanceof Class_ || !$this->isService($original, $scope->getFile())) {
            return [];
        }

        $name = $class->getName();
        $doc = $node->getOriginalNode()->getDocComment()?->getText() ?? '';

        if (!str_contains($doc, self::TAG)) {
            return [
                RuleErrorBuilder::message(\sprintf(
                    'Application class %s has no `%s` docblock. Add one sentence saying what '
                    .'this service is responsible for — it is what docs/SERVICES.md is generated '
                    .'from, and how the next contributor finds this class instead of writing a '
                    .'duplicate (INV-10).',
                    $name,
                    self::TAG,
                ))->identifier('bentley.serviceMustDeclareResponsibility')->build(),
            ];
        }

        $sentence = $this->extract($doc);

        if ('' === $sentence) {
            return [
                RuleErrorBuilder::message(\sprintf(
                    'Application service %s declares `%s` with no sentence after it (INV-10).',
                    $name,
                    self::TAG,
                ))->identifier('bentley.serviceMustDeclareResponsibility')->build(),
            ];
        }

        foreach (self::CONJUNCTIONS as $conjunction) {
            if (!str_contains(' '.strtolower($sentence).' ', $conjunction)) {
                continue;
            }

            return [
                RuleErrorBuilder::message(\sprintf(
                    'Application service %s describes itself as "%s". A responsibility containing '
                    .'"%s" is two topics, not one — split this into two services (INV-10).',
                    $name,
                    $sentence,
                    trim($conjunction),
                ))->identifier('bentley.serviceHasTwoTopics')->build(),
            ];
        }

        return [];
    }

    /**
     * Is this a service, as opposed to a value object or a helper that happens to live here?
     *
     * The test is whether the class takes collaborators. A class with injected objects is
     * doing work on behalf of the application and owns a topic; a class with only scalars, or
     * no constructor at all, is a value (HealthProbeResult) or a formatter
     * (GeneratedFileHeader), and demanding a responsibility sentence from it would be
     * ceremony that makes docs/SERVICES.md less useful rather than more.
     *
     * Everything in Application/Service/ counts regardless, since that directory is the
     * declared home of services — including ones whose only dependency is an iterable of
     * tagged collaborators.
     */
    private function isService(Class_ $class, string $file): bool
    {
        if (Layer::isApplicationService($file)) {
            return true;
        }

        $constructor = $class->getMethod('__construct');

        if (!$constructor instanceof ClassMethod) {
            return false;
        }

        return array_any($constructor->params, static fn (Param $param): bool => self::isObjectType($param->type));
    }

    private static function isObjectType(?Node $type): bool
    {
        if ($type instanceof NullableType) {
            return self::isObjectType($type->type);
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            return array_any($type->types, static fn (Identifier|Name|IntersectionType $inner): bool => self::isObjectType($inner));
        }

        // Node\Name covers class-like types; the built-ins arrive as Node\Identifier.
        return $type instanceof Name;
    }

    /** Pull the sentence that follows the tag, stopping at a blank docblock line. */
    private function extract(string $doc): string
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
                // A blank line, another tag, or the end of the block closes the sentence.
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
