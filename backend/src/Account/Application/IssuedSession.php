<?php

declare(strict_types=1);

namespace App\Account\Application;

/**
 * Everything a caller needs after authenticating successfully.
 *
 * Returned by the Application layer with no idea that any of it will become a cookie — the
 * Api layer decides that (INV-08). Keeping the TTLs here rather than re-deriving them in the
 * controller means the cookie lifetime and the token lifetime cannot drift apart, which is
 * the kind of mismatch that produces "randomly logged out" bug reports.
 */
final readonly class IssuedSession
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        public string $userId,
        public string $username,
        public array $roles,
        public string $accessToken,
        public int $accessTtlSeconds,
        public string $refreshToken,
        public int $refreshTtlSeconds,
        public string $csrfToken,
    ) {
    }
}
