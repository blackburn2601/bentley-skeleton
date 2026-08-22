<?php

declare(strict_types=1);

namespace App\Account\Infrastructure\Security;

use App\Account\Application\AccessTokenIssuer;
use App\Shared\Domain\Clock;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * RS256 JWTs via LexikJWTAuthenticationBundle (ADR-0002).
 *
 * Asymmetric rather than shared-secret: only this service needs the private key, so a
 * component that merely verifies tokens cannot mint them. That matters the first time
 * something else in the estate wants to check a token.
 *
 * The payload carries `sub`, `email`, `roles` and `perm_v` — and deliberately **no permission
 * list** (ADR-0011). `perm_v` is the user's acl_version, which is what lets a revoked grant
 * take effect on the next request instead of when the token expires.
 */
final readonly class JwtAccessTokenIssuer implements AccessTokenIssuer
{
    public function __construct(
        private JWTEncoderInterface $encoder,
        private Clock $clock,
        private int $ttlSeconds = 600,
    ) {
    }

    public function issue(Uuid $userId, string $email, array $roleNames, int $aclVersion): string
    {
        $now = $this->clock->now()->getTimestamp();

        return $this->encoder->encode([
            'sub' => $userId->toRfc4122(),
            'email' => $email,
            'roles' => array_values($roleNames),
            'perm_v' => $aclVersion,
            // A unique id per token, so a specific one can be named in an audit trail.
            'jti' => bin2hex(random_bytes(16)),
            'iat' => $now,
            'exp' => $now + $this->ttlSeconds,
        ]);
    }

    public function decode(string $token): ?array
    {
        try {
            $payload = $this->encoder->decode($token);
        } catch (Throwable) {
            // Expired, tampered, wrong key, not a JWT at all — the caller's response is the
            // same in every case, and distinguishing them for the client would only help
            // someone probing.
            return null;
        }

        return \is_array($payload) ? $this->claimsFrom($payload) : null;
    }

    public function ttlSeconds(): int
    {
        return $this->ttlSeconds;
    }

    /**
     * Validate the claims we rely on, and discard anything else.
     *
     * The signature proves the token came from us; it does not prove the payload has the shape
     * this code expects. A token minted by an older version, or by a sibling service sharing
     * the key, could be missing `perm_v` entirely — so every claim is checked rather than
     * assumed, and a malformed one fails authentication instead of producing a half-built user.
     *
     * @param array<array-key, mixed> $payload
     *
     * @return array{sub: string, email: string, roles: list<string>, perm_v: int}|null
     */
    private function claimsFrom(array $payload): ?array
    {
        $sub = $payload['sub'] ?? null;
        $email = $payload['email'] ?? null;

        if (!\is_string($sub) || !\is_string($email) || !Uuid::isValid($sub)) {
            return null;
        }

        $roles = $payload['roles'] ?? [];
        $permissionVersion = $payload['perm_v'] ?? 0;

        return [
            'sub' => $sub,
            'email' => $email,
            'roles' => \is_array($roles) ? array_values(array_filter($roles, \is_string(...))) : [],
            'perm_v' => \is_int($permissionVersion) ? $permissionVersion : 0,
        ];
    }
}
