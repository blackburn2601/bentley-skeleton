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
 * The payload carries `sub`, `username`, `roles`, `perm_v`, `amr` and `mfa` — and deliberately
 * **no permission list** (ADR-0011). `perm_v` is the user's acl_version, which is what lets a
 * revoked grant take effect on the next request instead of when the token expires.
 *
 * `amr` (RFC 8257) and `mfa` (ADR-0026) state *how* the caller authenticated. A fully verified
 * session carries `amr: ['totp']` and `mfa: 'verified'`; a pending second factor carries
 * `mfa: 'pending'` and `amr: []`; a floor user with no MFA carries neither beyond `amr: []`.
 */
final readonly class JwtAccessTokenIssuer implements AccessTokenIssuer
{
    public function __construct(
        private JWTEncoderInterface $encoder,
        private Clock $clock,
        private int $ttlSeconds = 600,
        private int $challengeTtlSeconds = 120,
    ) {
    }

    public function issue(Uuid $userId, string $username, array $roleNames, int $aclVersion, array $amr = []): string
    {
        $now = $this->clock->now()->getTimestamp();
        $methods = array_values(array_filter($amr, \is_string(...)));

        $payload = [
            'sub' => $userId->toRfc4122(),
            'username' => $username,
            'roles' => array_values($roleNames),
            'perm_v' => $aclVersion,
            'amr' => $methods,
            // A unique id per token, so a specific one can be named in an audit trail.
            'jti' => bin2hex(random_bytes(16)),
            'iat' => $now,
            'exp' => $now + $this->ttlSeconds,
        ];

        // Only claim the second factor when one was completed. A token with no `mfa` claim
        // reads back as a non-MFA session, which is what floor users and pre-0026 tokens are.
        if ([] !== $methods) {
            $payload['mfa'] = 'verified';
        }

        return $this->encoder->encode($payload);
    }

    public function issueChallenge(Uuid $userId, string $username, int $aclVersion): string
    {
        $now = $this->clock->now()->getTimestamp();

        return $this->encoder->encode([
            'sub' => $userId->toRfc4122(),
            'username' => $username,
            // No roles while pending: the MfaStageVoter denies everything but the verify
            // endpoints, and an empty role list means the AclVoter denies every permission
            // even if the voter were bypassed — two layers, fail-closed.
            'roles' => [],
            'perm_v' => $aclVersion,
            'amr' => [],
            'mfa' => 'pending',
            'jti' => bin2hex(random_bytes(16)),
            'iat' => $now,
            'exp' => $now + $this->challengeTtlSeconds,
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

    public function challengeTtlSeconds(): int
    {
        return $this->challengeTtlSeconds;
    }

    /**
     * Validate the claims we rely on, and discard anything else.
     *
     * The signature proves the token came from us; it does not prove the payload has the shape
     * this code expects. A token minted by an older version, or by a sibling service sharing
     * the key, could be missing `perm_v` or `amr` entirely — so every claim is checked rather
     * than assumed, and a malformed one fails authentication instead of producing a half-built
     * user.
     *
     * @param array<array-key, mixed> $payload
     *
     * @return array{sub: string, username: string, roles: list<string>, perm_v: int, amr: list<string>, mfa: ?string}|null
     */
    private function claimsFrom(array $payload): ?array
    {
        $sub = $payload['sub'] ?? null;
        $username = $payload['username'] ?? null;

        if (!\is_string($sub) || !\is_string($username) || !Uuid::isValid($sub)) {
            return null;
        }

        $roles = $payload['roles'] ?? [];
        $permissionVersion = $payload['perm_v'] ?? 0;
        $amr = $payload['amr'] ?? [];
        $mfa = $payload['mfa'] ?? null;

        return [
            'sub' => $sub,
            'username' => $username,
            'roles' => $this->stringList($roles),
            'perm_v' => \is_int($permissionVersion) ? $permissionVersion : 0,
            'amr' => $this->stringList($amr),
            'mfa' => \is_string($mfa) ? $mfa : null,
        ];
    }

    /**
     * Coerce a claim into a list of strings, dropping anything that is not one.
     *
     * Used for `roles` and `amr`, both of which must be `list<string>` even on a token minted by
     * an older version that omitted the claim or stored it differently.
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return \is_array($value) ? array_values(array_filter($value, \is_string(...))) : [];
    }
}
