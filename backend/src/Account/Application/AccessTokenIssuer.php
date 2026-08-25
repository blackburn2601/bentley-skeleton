<?php

declare(strict_types=1);

namespace App\Account\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Mints and reads short-lived access tokens.
 *
 * A port because the token format is an infrastructure decision. The Application layer cares
 * that a caller can be identified, not that the mechanism happens to be RS256 JWT.
 */
interface AccessTokenIssuer
{
    /**
     * @param list<string> $roleNames Symfony roles only — never a permission list (ADR-0011)
     * @param list<string> $amr       authentication-method references (ADR-0026); ['totp']
     *                                once the caller has completed the second factor
     */
    public function issue(Uuid $userId, string $username, array $roleNames, int $aclVersion, array $amr = []): string;

    /**
     * The half-authenticated token minted while a second factor is pending (ADR-0026).
     *
     * Carries `mfa: 'pending'` and `roles: []` and is deliberately short-lived. It reuses the
     * access-token cookie slot rather than introducing a fourth credential, and it carries no
     * `amr` — the second factor has not been completed yet.
     */
    public function issueChallenge(Uuid $userId, string $username, int $aclVersion): string;

    /**
     * @return array{sub: string, username: string, roles: list<string>, perm_v: int, amr: list<string>, mfa: ?string}|null
     *                                                                                                                      null if absent, malformed, expired or badly signed
     */
    public function decode(string $token): ?array;

    public function ttlSeconds(): int;

    /** Lifetime of the half-authenticated challenge token (ADR-0026). */
    public function challengeTtlSeconds(): int;
}
