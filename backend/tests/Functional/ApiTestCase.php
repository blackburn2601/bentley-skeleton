<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Account\Domain\User;
use App\Acl\Application\Service\EnsureBaselineRolesService;
use App\Acl\Application\Service\SyncPermissionsService;
use App\Acl\Domain\AclEffect;
use App\Acl\Domain\AclEntry;
use App\Acl\Domain\AclSubjectType;
use App\Acl\Domain\Permission;
use App\Acl\Domain\Role;
use App\Acl\Domain\UserRole;
use App\Api\Security\AuthCookies;
use App\Shared\Domain\Clock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Shared machinery for tests that go through the real HTTP kernel.
 *
 * Deliberately does NOT provide a "log in as an admin" shortcut that bypasses the login
 * endpoint. Functional tests authenticate the way a client does, so the authenticator, the
 * cookie handling and the voter are all exercised — a helper that injects a token would test
 * the controllers while skipping most of the security layer.
 */
abstract class ApiTestCase extends WebTestCase
{
    // Every concrete method below is `final`. This class is shared machinery, not an extension
    // point: a subclass that overrode logIn() or createUser() would quietly change what the
    // security layer is being exercised with, and the test that relied on it would still look
    // correct. Add helpers here, do not override them.

    protected const string PASSWORD = 'functional-test-password-9x';

    protected KernelBrowser $client;
    protected EntityManagerInterface $em;
    protected Clock $clock;

    final protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->clock = $container->get(Clock::class);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers server-style keys, e.g. HTTP_X_CSRF_TOKEN
     */
    final protected function json(string $method, string $uri, array $body = [], array $headers = []): void
    {
        $this->client->request(
            $method,
            $uri,
            server: ['CONTENT_TYPE' => 'application/json'] + $headers,
            content: [] === $body ? '' : (string) json_encode($body),
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    final protected function responseJson(): array
    {
        $content = $this->client->getResponse()->getContent();
        $decoded = json_decode(false === $content ? '' : $content, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * One string field out of the last response, asserted to be there.
     *
     * responseJson() is deliberately typed as array<array-key, mixed>, because a JSON body can
     * be anything. Casting mixed at each call site is what PHPStan objects to, and rightly:
     * this asserts the shape instead.
     */
    final protected function responseString(string $key): string
    {
        $body = $this->responseJson();

        self::assertArrayHasKey($key, $body);
        $value = $body[$key];
        self::assertIsString($value);

        return $value;
    }

    /**
     * One list-of-strings field out of the last response, asserted to be there.
     *
     * Pass a dotted path for a nested object, e.g. `access.roles`.
     *
     * @return list<string>
     */
    final protected function responseList(string $key): array
    {
        /** @var array<array-key, mixed> $body */
        $body = $this->responseJson();

        foreach (explode('.', $key) as $segment) {
            self::assertArrayHasKey($segment, $body);
            $next = $body[$segment];
            self::assertIsArray($next);
            $body = $next;
        }

        $value = $body;
        self::assertIsArray($value);

        $strings = [];
        foreach ($value as $item) {
            self::assertIsString($item);
            $strings[] = $item;
        }

        return $strings;
    }

    /**
     * The last response as a paginated envelope, with the envelope itself asserted.
     *
     * Every collection in this API returns the same four keys (ADR-0019), so the shape is
     * checked once here rather than in each list endpoint's test — and a test that reads
     * `$page['total']` gets an int rather than a mixed it has to cast.
     *
     * @return array{items: list<array<string, mixed>>, page: int, perPage: int, total: int}
     */
    final protected function pageJson(): array
    {
        $body = $this->responseJson();

        self::assertArrayHasKey('items', $body);
        self::assertArrayHasKey('page', $body);
        self::assertArrayHasKey('perPage', $body);
        self::assertArrayHasKey('total', $body);

        $items = $body['items'];
        $page = $body['page'];
        $perPage = $body['perPage'];
        $total = $body['total'];

        self::assertIsArray($items);
        self::assertIsInt($page);
        self::assertIsInt($perPage);
        self::assertIsInt($total);

        $rows = [];
        foreach (array_values($items) as $row) {
            self::assertIsArray($row);
            /** @var array<string, mixed> $row */
            $rows[] = $row;
        }

        return ['items' => $rows, 'page' => $page, 'perPage' => $perPage, 'total' => $total];
    }

    /**
     * One column out of a paginated envelope's rows, as strings.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return list<string>
     */
    final protected function column(array $items, string $key): array
    {
        return array_map(static function (array $row) use ($key): string {
            $value = $row[$key] ?? '';

            return \is_scalar($value) ? (string) $value : '';
        }, $items);
    }

    /**
     * Log in through the real endpoint and keep the cookies for subsequent requests.
     */
    final protected function logIn(User $user): void
    {
        $this->json('POST', '/api/v1/auth/login', [
            'email' => $user->email(),
            'password' => self::PASSWORD,
        ]);

        self::assertResponseIsSuccessful('Fixture login should succeed; check the user is Active and verified.');
    }

    final protected function logOut(): void
    {
        $this->client->getCookieJar()->clear();
    }

    /**
     * The CSRF value the SPA would echo back, read from the cookie the server set.
     *
     * @return array<string, string>
     */
    final protected function csrfHeader(): array
    {
        $cookie = $this->client->getCookieJar()->get(AuthCookies::CSRF);

        return null === $cookie ? [] : ['HTTP_'.str_replace('-', '_', strtoupper(AuthCookies::CSRF_HEADER)) => $cookie->getValue()];
    }

    /**
     * Re-read an entity from the database.
     *
     * Use after a request that changed it: the in-memory instance this test holds predates the
     * write and will happily report stale values.
     *
     * `find()` alone does NOT do this. It returns whatever is in the identity map, and this
     * test's EntityManager already tracks any entity it created — so after a request wrote to
     * the row through a different manager, find() hands back the stale instance and the
     * assertion passes or fails on data from before the request. `refresh()` is what actually
     * issues the SELECT.
     */
    final protected function reload(User $user): User
    {
        $fresh = $this->em->getRepository(User::class)->find($user->id());
        self::assertInstanceOf(User::class, $fresh);

        $this->em->refresh($fresh);

        return $fresh;
    }

    final protected function createUser(string $label, bool $verified = true): User
    {
        $hasher = static::getContainer()->get(PasswordHasherFactoryInterface::class)
            ->getPasswordHasher('app_account_password');

        $user = new User(
            \sprintf('%s-%s@functional.test', $label, bin2hex(random_bytes(5))),
            $hasher->hash(self::PASSWORD),
            $this->clock->now(),
        );

        if ($verified) {
            $user->verifyEmail($this->clock->now());
        }

        $this->em->persist($user);
        $this->em->flush();

        // Every real account gets the baseline role at registration, and that role only means
        // anything once app:acl:sync-permissions has granted it the account.* permissions. A
        // test user built directly needs both, or every assertion becomes a 403 for the wrong
        // reason — which looks exactly like a genuine authorization bug.
        $this->ensureBaselineRole();
        $this->assignRole($user, Role::DEFAULT_USER);

        return $user;
    }

    /**
     * Seed the baseline role and its permissions, as the sync command would.
     *
     * Called through the real service rather than reimplemented here, so the tests and
     * production agree on what "baseline" means.
     */
    final protected function ensureBaselineRole(): void
    {
        // Permissions FIRST. EnsureBaselineRolesService only grants what already exists as a
        // row, so running it against an empty permission table creates the role with nothing
        // attached — and every request then 403s for a reason that looks exactly like a real
        // authorization bug. Same order as app:acl:sync-permissions.
        static::getContainer()->get(SyncPermissionsService::class)();
        static::getContainer()->get(EnsureBaselineRolesService::class)();
    }

    final protected function assignRole(User $user, string $roleName): void
    {
        $role = $this->em->getRepository(Role::class)->findOneBy(['name' => $roleName]);

        if (!$role instanceof Role) {
            $role = new Role($roleName, $this->clock->now());
            $this->em->persist($role);
            $this->em->flush();
        }

        $this->em->persist(new UserRole($user->id(), $role, $this->clock->now()));
        $this->em->flush();
        $user->bumpAclVersion();
        $this->em->flush();
    }

    final protected function grantRolePermission(string $roleName, string $permissionName): void
    {
        $role = $this->em->getRepository(Role::class)->findOneBy(['name' => $roleName]);
        self::assertInstanceOf(Role::class, $role);

        $role->grant($this->permission($permissionName));
        $this->em->flush();
    }

    final protected function grantOnObject(User $subject, string $resourceClass, Uuid $resourceId, string $permissionName, AclEffect $effect = AclEffect::Allow): void
    {
        $this->em->persist(new AclEntry(
            AclSubjectType::User,
            $subject->id(),
            $resourceClass,
            $resourceId,
            $this->permission($permissionName),
            $effect,
            $this->clock->now(),
        ));
        $this->em->flush();
        $subject->bumpAclVersion();
        $this->em->flush();
    }

    final protected function permission(string $name): Permission
    {
        $permission = $this->em->getRepository(Permission::class)->findOneBy(['name' => $name]);

        if (!$permission instanceof Permission) {
            $permission = new Permission($name, $this->clock->now());
            $this->em->persist($permission);
            $this->em->flush();
        }

        return $permission;
    }
}
