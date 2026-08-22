<?php

declare(strict_types=1);

namespace App\Acl\Application;

use App\Shared\Application\AclVersionProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * @responsibility Caches permission decisions under a key that a grant change invalidates.
 *
 * Two layers, for two different problems:
 *
 *   - **Per-request memoisation.** A page rendering fifty rows asks the same class-level
 *     question fifty times. This is a plain array and it removes almost all of the load.
 *   - **Redis, keyed by the user's acl_version.** Survives between requests.
 *
 * The version is the whole trick (ADR-0011). Bumping `user.acl_version` changes every key
 * that user has, so a grant change orphans the old entries atomically — nothing is
 * enumerated, nothing is deleted, and a concurrent reader either finds the old key (and
 * recomputes) or the new one. There is no window in which a stale permission is served, and
 * no invalidation sweep to get wrong.
 *
 * Deliberately not cached: `explain()`. An explanation of a cached decision would describe
 * whenever the cache was filled, which is exactly the confusion it exists to clear up.
 */
final class AclCache
{
    /** @var array<string, bool> */
    private array $memo = [];

    public function __construct(
        private readonly CacheInterface&CacheItemPoolInterface $aclCache,
        private readonly AclVersionProvider $versions,
        private readonly int $ttlSeconds = 300,
    ) {
    }

    /**
     * @param callable(): bool $compute
     */
    public function remember(Uuid $userId, string $permission, ?object $resource, callable $compute): bool
    {
        $key = $this->key($userId, $permission, $resource);

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $value = $this->aclCache->get($key, function (ItemInterface $item) use ($compute): bool {
            $item->expiresAfter($this->ttlSeconds);

            return $compute();
        });

        return $this->memo[$key] = $value;
    }

    /**
     * Drop this request's memo.
     *
     * Needed after a grant changes within the same request — an admin granting a permission
     * and then rendering the result would otherwise see the pre-grant answer it memoised
     * moments earlier.
     */
    public function forgetRequestScope(): void
    {
        $this->memo = [];
    }

    private function key(Uuid $userId, string $permission, ?object $resource): string
    {
        $resourcePart = 'class';

        if (null !== $resource) {
            $id = method_exists($resource, 'id') ? $resource->id() : null;
            $resourcePart = $resource::class.':'.($id instanceof Uuid ? $id->toRfc4122() : 'unknown');
        }

        // The version sits in the key rather than being used to purge entries.
        return \sprintf(
            'acl.%s.v%d.%s.%s',
            $userId->toRfc4122(),
            $this->versions->versionFor($userId),
            // PSR-6 forbids {}()/\@: in keys, and a resource class is full of backslashes.
            hash('xxh128', $permission),
            hash('xxh128', $resourcePart),
        );
    }
}
