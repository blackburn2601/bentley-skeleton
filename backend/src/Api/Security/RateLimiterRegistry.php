<?php

declare(strict_types=1);

namespace App\Api\Security;

use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The rate-limit policies, by name.
 *
 * A plain typed map rather than a service locator. A locator would work, but its type is
 * `ContainerInterface`, and injecting a container is exactly what INV-12's sibling rule
 * (`noParameterWithContainerTypeDeclaration`) forbids — for the good reason that a service
 * holding a container can reach anything, which defeats every layering rule at once.
 *
 * A locator is genuinely narrower than that, but the type cannot say so. Listing the
 * factories explicitly costs one line of configuration each and makes the set of policies
 * greppable, which is the property that matters: "what limits exist?" should not require
 * reading a subscriber.
 */
final readonly class RateLimiterRegistry
{
    /**
     * @param array<string, RateLimiterFactoryInterface> $factories keyed by the policy names
     *                                                              in rate_limiter.yaml
     */
    public function __construct(private array $factories)
    {
    }

    public function get(string $policy): ?RateLimiterFactoryInterface
    {
        return $this->factories[$policy] ?? null;
    }
}
