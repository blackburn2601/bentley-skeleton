<?php

declare(strict_types=1);

namespace App\Tests\Architecture\PhpStanRules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Use_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * INV-07: the Api layer never touches persistence.
 *
 * A controller that can reach the EntityManager will eventually contain a query, then a
 * conditional on the result, and the authorization decision quietly moves out of the ACL
 * and into HTTP code where nothing tests it. Blocking the import is the cheapest place to
 * stop that.
 *
 * @implements Rule<Use_>
 */
final class NoDoctrineInControllerRule implements Rule
{
    public function getNodeType(): string
    {
        return Use_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!Layer::isApi($scope->getFile())) {
            return [];
        }

        $errors = [];
        foreach ($node->uses as $use) {
            $imported = $use->name->toString();

            if (!$this->isPersistence($imported)) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(\sprintf(
                'Controller imports %s. The Api layer must not touch persistence: move the '
                .'query into an Application service and call that instead (docs/cookbook/add-endpoint.md).',
                $imported,
            ))->identifier('bentley.noDoctrineInController')->line($use->getStartLine())->build();
        }

        return $errors;
    }

    private function isPersistence(string $class): bool
    {
        return str_starts_with($class, 'Doctrine\\')
            || str_ends_with($class, 'Repository')
            || str_ends_with($class, 'RepositoryInterface');
    }
}
