<?php

declare(strict_types=1);

namespace App\Platform\Infrastructure\Health;

use App\Platform\Application\HealthProbe;
use App\Platform\Application\HealthProbeResult;
use Psr\Cache\CacheItemPoolInterface;
use Throwable;

/**
 * Is the cache backend reachable?
 *
 * Redis holds rate-limit counters, the ACL cache and the lock store. None of them are a
 * source of truth (ADR-0009), so losing Redis degrades rather than corrupts — but a replica
 * that cannot reach it should not be taking traffic.
 */
final readonly class CacheProbe implements HealthProbe
{
    public function __construct(private CacheItemPoolInterface $cache)
    {
    }

    public function name(): string
    {
        return 'cache';
    }

    public function check(): HealthProbeResult
    {
        try {
            $this->cache->getItem('health.probe')->isHit();

            return HealthProbeResult::up();
        } catch (Throwable $e) {
            return HealthProbeResult::down($e::class);
        }
    }
}
