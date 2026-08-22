<?php

declare(strict_types=1);

namespace App\Account\Application;

/**
 * Has this password appeared in a known breach?
 *
 * A port because it is network I/O and must be switchable off: CI and offline development
 * cannot depend on a third-party service being reachable, and a check that fails closed
 * would lock everyone out of registration when HIBP has a bad day.
 */
interface BreachedPasswordChecker
{
    /**
     * Implementations MUST fail open — return false when the service is unreachable.
     *
     * The check is a defence-in-depth improvement, not an authentication control. Failing
     * closed would convert someone else's outage into an outage of our registration and
     * password-reset flows, which is a worse security outcome than briefly accepting a
     * password we would otherwise have discouraged.
     */
    public function isBreached(string $password): bool;
}
