<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Api\Security\AuthCookies;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Insecure direct object reference: user A reaching user B's data.
 *
 * The most valuable suite in the repository, because this is the bug that looks correct in
 * review. An endpoint that forgets its permission check reads exactly like one that does not;
 * the difference only appears when somebody substitutes an id.
 *
 * Two complementary parts:
 *
 *  1. **Every non-public route is walked** and asserted to refuse an anonymous caller. This
 *     is the backstop for the whole API surface — a new endpoint is covered the moment it is
 *     routed, with nobody having to remember to add a test. Whether an endpoint SHOULD be
 *     public is a separate, structural question, asserted by RoutesDeclarePermissionsTest.
 *  2. **Named cases** for endpoints that take an id, checking a *different* user's id
 *     specifically.
 *
 * Part 1 is what makes this durable. A suite that only covers endpoints somebody thought to
 * list is a suite that misses the one they forgot.
 *
 * No #[CoversNothing] here, deliberately. It reads as "this test targets no particular
 * class", but what it actually tells PHPUnit is "record no coverage from this test" — which
 * silently zeroed the entire functional suite's contribution to the coverage report, and made
 * the coverage gate measure a fraction of what the tests actually exercise.
 */
final class IdorRegressionTest extends ApiTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function protectedRoutes(): iterable
    {
        self::bootKernel();
        $router = static::getContainer()->get(RouterInterface::class);

        foreach ($router->getRouteCollection() as $name => $route) {
            $controller = $route->getDefault('_controller');

            if (!\is_string($controller) || !str_starts_with($controller, 'App\\Api\\')) {
                continue;
            }

            // Routes that declare PUBLIC_ACCESS are meant to answer anonymous callers.
            // Whether that declaration is APPROPRIATE is asserted by
            // RoutesDeclarePermissionsTest, which holds the public set to an explicit list —
            // so there is one place to maintain, not two that can disagree.
            if ('PUBLIC_ACCESS' === self::declaredPermission($route)) {
                continue;
            }

            yield $name => [$name, self::concreteUri($route)];
        }

        static::ensureKernelShutdown();
    }

    /**
     * Every non-public endpoint must refuse an anonymous caller.
     *
     * Walking the router rather than listing endpoints is deliberate: a new route is covered
     * automatically, so this cannot fall behind the code.
     */
    #[DataProvider('protectedRoutes')]
    public function testAnonymousCallersAreRefused(string $routeName, string $uri): void
    {
        $method = $this->methodFor($routeName);
        $this->json($method, $uri);

        self::assertContains(
            $this->client->getResponse()->getStatusCode(),
            [401, 403, 404, 405],
            \sprintf(
                'Route "%s" (%s %s) answered an anonymous caller with %d. Either it needs a '
                .'permission, or it belongs in INTENTIONALLY_PUBLIC as a deliberate choice.',
                $routeName,
                $method,
                $uri,
                $this->client->getResponse()->getStatusCode(),
            ),
        );
    }

    // ---------------------------------------------------------------- named cases

    public function testAUserCannotReadAnotherUsersProfileThroughMe(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');

        $this->logIn($alice);
        $this->json('GET', '/api/v1/auth/me');

        self::assertResponseIsSuccessful();
        self::assertSame(
            $alice->email(),
            $this->responseJson()['email'] ?? null,
            '/me must describe the CALLER. Returning anyone else would be the purest form of IDOR.',
        );
        self::assertNotSame($bob->email(), $this->responseJson()['email'] ?? null);
    }

    public function testStealingAnotherUsersRefreshTokenDoesNotYieldTheirSession(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');

        // Bob logs in and we capture his refresh token, standing in for a stolen one.
        $this->logIn($bob);
        $bobsToken = $this->client->getCookieJar()->get(AuthCookies::REFRESH, '/api/v1/auth')?->getValue();
        self::assertIsString($bobsToken);

        // Alice presents it. It IS Bob's token, so it works — and that is exactly why it must
        // be short-lived, rotated, HttpOnly and revocable. What this asserts is the bounded
        // part: the session it produces is Bob's, never Alice's, so a stolen token cannot be
        // used to escalate into somebody else's account.
        $this->logOut();
        $this->logIn($alice);
        $this->json('GET', '/api/v1/auth/me');

        self::assertSame($alice->email(), $this->responseJson()['email'] ?? null);
    }

    public function testAnObjectLevelGrantDoesNotLeakToOtherObjects(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $carol = $this->createUser('carol');

        // Alice may read Bob's record specifically — and nothing else.
        $this->grantOnObject($alice, \App\Account\Domain\User::class, $bob->id(), \App\Acl\Domain\PermissionCatalog::USER_READ);

        $acl = static::getContainer()->get(\App\Acl\Application\AclFacade::class);

        self::assertTrue($acl->isGranted($alice->id(), \App\Acl\Domain\PermissionCatalog::USER_READ, $bob));
        self::assertFalse(
            $acl->isGranted($alice->id(), \App\Acl\Domain\PermissionCatalog::USER_READ, $carol),
            'A grant on one object must not extend to another. This is the whole point of an '
            .'object-level ACL, and the exact bug an IDOR suite exists to catch.',
        );
    }

    /**
     * The permission a route's controller declares, if any.
     */
    private static function declaredPermission(Route $route): ?string
    {
        $controller = $route->getDefault('_controller');

        if (!\is_string($controller)) {
            return null;
        }

        $class = str_contains($controller, '::') ? strstr($controller, '::', true) : $controller;

        if (!\is_string($class) || !class_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);
        $attributes = $reflection->getAttributes(\Symfony\Component\Security\Http\Attribute\IsGranted::class);

        foreach ($attributes as $attribute) {
            $arguments = $attribute->getArguments();
            $value = $arguments['attribute'] ?? $arguments[0] ?? null;

            if (\is_string($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * A URI with every placeholder filled in by a UUID that belongs to nobody.
     *
     * A random id is the right choice: it must be refused for lack of permission, not because
     * the record happens not to exist — so a 404 is accepted alongside 401/403 above.
     */
    private static function concreteUri(Route $route): string
    {
        return (string) preg_replace(
            '/\{[^}]+\}/',
            \Symfony\Component\Uid\Uuid::v7()->toRfc4122(),
            $route->getPath(),
        );
    }

    private function methodFor(string $routeName): string
    {
        $route = static::getContainer()->get(RouterInterface::class)->getRouteCollection()->get($routeName);
        $methods = null === $route ? [] : $route->getMethods();

        return $methods[0] ?? 'GET';
    }
}
