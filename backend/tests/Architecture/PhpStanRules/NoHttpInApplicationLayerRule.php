<?php

declare(strict_types=1);

namespace App\Tests\Architecture\PhpStanRules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Use_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * INV-08: Application services know nothing about HTTP.
 *
 * A service that reads the Request cannot be called from a console command, a test, or
 * another service without faking a web request — and it tends to grow HTTP status codes
 * into its return values, which is how the error contract (ADR-0007) gets bypassed.
 * Services take DTOs and throw domain exceptions; the problem+json listener is the single
 * place that knows about status codes.
 *
 * @implements Rule<Use_>
 */
final class NoHttpInApplicationLayerRule implements Rule
{
    /** Prefixes that are unambiguously HTTP-transport concerns. */
    private const array FORBIDDEN_PREFIXES = [
        'Symfony\\Component\\HttpFoundation\\',
        'Symfony\\Component\\HttpKernel\\',
        'Symfony\\Component\\Security\\Http\\',
    ];

    public function getNodeType(): string
    {
        return Use_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $file = $scope->getFile();
        if (!Layer::isApplication($file) && !Layer::isDomain($file)) {
            return [];
        }

        $layer = Layer::isDomain($file) ? 'Domain' : 'Application';
        $errors = [];

        foreach ($node->uses as $use) {
            $imported = $use->name->toString();

            foreach (self::FORBIDDEN_PREFIXES as $prefix) {
                if (!str_starts_with($imported, $prefix)) {
                    continue;
                }

                $errors[] = RuleErrorBuilder::message(\sprintf(
                    '%s layer imports %s. This layer must not know about HTTP: accept a DTO '
                    .'and throw a domain exception — the problem+json listener maps it to a '
                    .'status code (ADR-0007).',
                    $layer,
                    $imported,
                ))->identifier('bentley.noHttpInApplicationLayer')->line($use->getStartLine())->build();
            }
        }

        return $errors;
    }
}
