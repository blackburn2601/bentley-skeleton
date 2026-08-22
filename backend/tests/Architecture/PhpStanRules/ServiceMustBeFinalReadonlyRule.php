<?php

declare(strict_types=1);

namespace App\Tests\Architecture\PhpStanRules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * INV-09: Application services are `final readonly`, with constructor injection only.
 *
 * `final` because a subclass that overrides one method turns a single-topic service into
 * two behaviours sharing one name, and the tests for the parent no longer prove anything
 * about the child. `readonly` because mutable state on a service shared across a request
 * is the classic source of bugs that only appear under load.
 *
 * @implements Rule<InClassNode>
 */
final class ServiceMustBeFinalReadonlyRule implements Rule
{
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
        if ($class->isInterface() || $class->isAbstract() || $class->isEnum()) {
            return [];
        }

        $name = $class->getName();
        $missing = [];
        if (!$class->isFinal()) {
            $missing[] = 'final';
        }
        if (!$class->isReadOnly()) {
            $missing[] = 'readonly';
        }

        if ([] === $missing) {
            return [];
        }

        return [
            RuleErrorBuilder::message(\sprintf(
                'Application service %s must be declared `final readonly` (missing: %s). '
                .'Services hold no mutable state and are never subclassed — see INV-09.',
                $name,
                implode(' and ', $missing),
            ))->identifier('bentley.serviceMustBeFinalReadonly')->build(),
        ];
    }
}
