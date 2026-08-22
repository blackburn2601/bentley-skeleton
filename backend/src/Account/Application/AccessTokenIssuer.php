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
     */
    public function issue(Uuid $userId, string $email, array $roleNames, int $aclVersion): string;

    /**
     * @return array{sub: string, email: string, roles: list<string>, perm_v: int}|null
     *                                                                                  null if absent, malformed, expired or badly signed
     */
    public function decode(string $token): ?array;

    public function ttlSeconds(): int;
}
