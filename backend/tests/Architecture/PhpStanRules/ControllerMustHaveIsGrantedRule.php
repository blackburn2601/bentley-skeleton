<?php

declare(strict_types=1);

namespace App\Tests\Architecture\PhpStanRules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * INV-11: every endpoint declares its authorization requirement in code.
 *
 * The failure mode this prevents is not a wrong permission — it is a *missing* one. An
 * endpoint with no `#[IsGranted]` is silently public, looks exactly like a correct one in
 * review, and only shows up when someone reads a resource they should not. Requiring the
 * attribute means forgetting it is a build failure rather than a disclosure.
 *
 * A functional test walks the real router and asserts the same thing, because this rule
 * can only see code it is pointed at; the two together leave no gap.
 *
 * Public endpoints are not an exception to be argued case by case: mark them
 * `#[IsGranted('PUBLIC_ACCESS')]` so the decision is visible and greppable.
 *
 * @implements Rule<InClassNode>
 */
final class ControllerMustHaveIsGrantedRule implements Rule
{
    private const string ATTRIBUTE = IsGranted::class;

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!Layer::isApi($scope->getFile())) {
            return [];
        }

        $class = $node->getClassReflection();
        if ($class->isInterface() || $class->isAbstract() || $class->isEnum()) {
            return [];
        }

        $original = $node->getOriginalNode();
        if (!$original instanceof Class_) {
            return [];
        }
        $invoke = array_find($original->getMethods(), static fn ($method): bool => '__invoke' === $method->name->toLowerString());

        if (null === $invoke) {
            return [];
        }

        if ($this->hasIsGranted($original) || $this->hasIsGranted($invoke)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(\sprintf(
                'Endpoint %s has no #[IsGranted] attribute, so it is publicly reachable. Declare '
                .'the permission it requires — or #[IsGranted(\'PUBLIC_ACCESS\')] if it is '
                .'genuinely public, so that the choice is visible (INV-11).',
                $class->getName(),
            ))->identifier('bentley.controllerMustHaveIsGranted')->build(),
        ];
    }

    private function hasIsGranted(Class_|ClassMethod $node): bool
    {
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $name = ltrim($attribute->name->toString(), '\\');
                if ('IsGranted' === $name || self::ATTRIBUTE === $name) {
                    return true;
                }
            }
        }

        return false;
    }
}
