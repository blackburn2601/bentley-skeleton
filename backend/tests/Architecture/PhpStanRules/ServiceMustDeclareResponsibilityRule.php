<?php

declare(strict_types=1);

namespace App\Tests\Architecture\PhpStanRules;

use PhpParser\Node;
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
        if (!Layer::isApplicationService($scope->getFile())) {
            return [];
        }

        $class = $node->getClassReflection();
        if ($class->isInterface() || $class->isEnum()) {
            return [];
        }

        $name = $class->getName();
        $doc = $node->getOriginalNode()->getDocComment()?->getText() ?? '';

        if (!str_contains($doc, self::TAG)) {
            return [
                RuleErrorBuilder::message(\sprintf(
                    'Application service %s has no `%s` docblock. Add one sentence saying what '
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
