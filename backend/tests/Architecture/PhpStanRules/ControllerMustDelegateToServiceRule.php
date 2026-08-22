<?php

declare(strict_types=1);

namespace App\Tests\Architecture\PhpStanRules;

use App\Api\Attribute\NoServiceDelegation;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\UnionType;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * INV-07, second half: a controller delegates.
 *
 * `NoDoctrineInControllerRule` stops a controller reaching the database. This stops the
 * other shape of the same problem — a controller that does the work inline with no
 * persistence at all, which passes every other check while putting business logic somewhere
 * it can only be tested through an HTTP kernel.
 *
 * phpat can express this as `should()->dependOn()`, but it reports once per AST node, so a
 * single offending controller produces a dozen identical errors. One class, one message.
 *
 * @implements Rule<InClassNode>
 */
final class ControllerMustDelegateToServiceRule implements Rule
{
    private const string EXEMPTION = NoServiceDelegation::class;

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
        if (!$original instanceof Class_ || !str_ends_with($class->getName(), 'Controller')) {
            return [];
        }

        // An explicit, reasoned exemption. See App\Api\Attribute\NoServiceDelegation.
        if ($this->isExempt($original)) {
            return [];
        }

        // Services arrive by constructor injection or as an __invoke argument; both count.
        foreach ($original->getMethods() as $method) {
            if (!\in_array($method->name->toLowerString(), ['__construct', '__invoke'], true)) {
                continue;
            }

            foreach ($method->params as $param) {
                if (self::isApplicationType($param->type)) {
                    return [];
                }
            }
        }

        return [
            RuleErrorBuilder::message(\sprintf(
                'Controller %s takes no Application service. An endpoint that does its own work '
                .'puts business logic behind an HTTP boundary, where it can only be tested by '
                .'making requests — inject the service that owns this topic (INV-07, '
                .'docs/cookbook/add-endpoint.md).',
                $class->getName(),
            ))->identifier('bentley.controllerMustDelegateToService')->build(),
        ];
    }

    private function isExempt(Class_ $class): bool
    {
        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $name = ltrim($attribute->name->toString(), '\\');

                if ('NoServiceDelegation' === $name || self::EXEMPTION === $name) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function isApplicationType(?Node $type): bool
    {
        if ($type instanceof NullableType) {
            return self::isApplicationType($type->type);
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            return array_any($type->types, static fn (Identifier|Name|IntersectionType $inner): bool => self::isApplicationType($inner));
        }

        if (!$type instanceof Name) {
            return false;
        }

        return 1 === preg_match('#^App\\\\[A-Za-z]+\\\\Application\\\\#', $type->toString());
    }
}
