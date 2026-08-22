<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Every route declares its authorization, and every public one is a listed decision.
 *
 * This walks the REAL compiled router, which is what makes it complementary to the PHPStan
 * rule rather than a duplicate of it:
 *
 *  - `ControllerMustHaveIsGrantedRule` reads source and catches a MISSING attribute. It cannot
 *    know whether a route exists for the class, and it cannot judge whether `PUBLIC_ACCESS` is
 *    appropriate.
 *  - This test sees what is actually reachable, and holds the set of public endpoints to an
 *    explicit list. Changing an endpoint from a permission to `PUBLIC_ACCESS` is a one-word
 *    edit that looks harmless in a diff; here it fails until someone adds it to the list,
 *    which turns "quietly made public" into "deliberately made public".
 *
 * The behavioural half — that anonymous callers are actually refused — lives in the IDOR
 * suite. Both are needed: an endpoint can carry the right attribute and still leak, and an
 * endpoint can refuse anonymous callers for incidental reasons while carrying no attribute at
 * all. (That second case is not hypothetical: a controller taking a non-nullable
 * `#[CurrentUser]` argument answers 401 whether or not it declares a permission, which is
 * exactly why the behavioural check alone is not enough.)
 *
 * No #[CoversNothing] here, deliberately. It reads as "this test targets no particular
 * class", but what it actually tells PHPUnit is "record no coverage from this test" — which
 * silently zeroed the entire functional suite's contribution to the coverage report, and made
 * the coverage gate measure a fraction of what the tests actually exercise.
 */
final class RoutesDeclarePermissionsTest extends KernelTestCase
{
    /**
     * Endpoints that are public on purpose.
     *
     * Every entry is a decision someone made and can be asked about. Adding one should feel
     * like a decision — which is the entire reason this list is hand-written rather than
     * derived.
     *
     * @var array<string, string> route name => why
     */
    private const array INTENTIONALLY_PUBLIC = [
        'auth_login' => 'The caller has no credentials yet; that is the point.',
        'auth_register' => 'Anonymous by definition.',
        'auth_verify_email' => 'The emailed token is the credential.',
        'auth_password_forgot' => 'Requested precisely because the user cannot sign in.',
        'auth_password_reset' => 'The emailed token is the credential; the whole point is that the password is unknown.',
        'auth_refresh' => 'The access token has expired by definition; the refresh cookie is the credential.',
        'auth_logout' => 'Must always clear cookies, even with an invalid session, or a client can be stranded.',
        'health_live' => 'An orchestrator has no credentials, and an authenticated liveness probe means no liveness probe.',
        'health_ready' => 'Same as health_live.',
        'metrics' => 'A Prometheus scraper has no credentials; restricted by IP instead, and 404s outside the allow-list.',
    ];

    public function testEveryRouteDeclaresAPermission(): void
    {
        $undeclared = [];

        foreach ($this->applicationRoutes() as $name => $class) {
            if (null === $this->declaredPermission($class)) {
                $undeclared[] = \sprintf('%s (%s)', $name, $class);
            }
        }

        self::assertSame([], $undeclared, \sprintf(
            "These routes are reachable but declare no #[IsGranted], so they are silently public:\n  %s\n"
            .'Declare the permission each one requires, or #[IsGranted(\'PUBLIC_ACCESS\')] if it is '
            .'genuinely public — and then add it to INTENTIONALLY_PUBLIC here (INV-11).',
            implode("\n  ", $undeclared),
        ));
    }

    public function testOnlyTheListedRoutesArePublic(): void
    {
        $unexpected = [];

        foreach ($this->applicationRoutes() as $name => $class) {
            if ('PUBLIC_ACCESS' === $this->declaredPermission($class) && !isset(self::INTENTIONALLY_PUBLIC[$name])) {
                $unexpected[] = \sprintf('%s (%s)', $name, $class);
            }
        }

        self::assertSame([], $unexpected, \sprintf(
            "These routes are PUBLIC_ACCESS but are not listed as intentionally public:\n  %s\n"
            .'Changing an endpoint from a permission to PUBLIC_ACCESS is a one-word edit that '
            .'reads as harmless. If it really should be public, add it to INTENTIONALLY_PUBLIC '
            .'with the reason; if not, restore its permission.',
            implode("\n  ", $unexpected),
        ));
    }

    public function testTheIntentionallyPublicListHasNoStaleEntries(): void
    {
        $routes = $this->applicationRoutes();
        $stale = array_diff(array_keys(self::INTENTIONALLY_PUBLIC), array_keys($routes));

        self::assertSame([], array_values($stale), \sprintf(
            'These routes no longer exist but are still listed as intentionally public: %s. '
            .'A stale exemption is how a future endpoint reusing the name becomes public by '
            .'accident.',
            implode(', ', $stale),
        ));
    }

    /**
     * @return array<string, class-string>
     */
    private function applicationRoutes(): array
    {
        self::bootKernel();
        $router = self::getContainer()->get(RouterInterface::class);

        $routes = [];

        foreach ($router->getRouteCollection() as $name => $route) {
            $controller = $route->getDefault('_controller');

            if (!\is_string($controller) || !str_starts_with($controller, 'App\\Api\\')) {
                continue;
            }

            $class = str_contains($controller, '::') ? strstr($controller, '::', true) : $controller;

            if (\is_string($class) && class_exists($class)) {
                /** @var class-string $class */
                $routes[$name] = $class;
            }
        }

        return $routes;
    }

    /**
     * @param class-string $class
     */
    private function declaredPermission(string $class): ?string
    {
        $reflection = new ReflectionClass($class);
        $attributes = $reflection->getAttributes(IsGranted::class);

        if ($reflection->hasMethod('__invoke')) {
            $attributes = [...$attributes, ...$reflection->getMethod('__invoke')->getAttributes(IsGranted::class)];
        }

        foreach ($attributes as $attribute) {
            $arguments = $attribute->getArguments();
            $value = $arguments['attribute'] ?? $arguments[0] ?? null;

            if (\is_string($value)) {
                return $value;
            }
        }

        return null;
    }
}
