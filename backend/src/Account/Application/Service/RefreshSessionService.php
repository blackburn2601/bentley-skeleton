<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Application\AccessTokenIssuer;
use App\Account\Application\IssuedSession;
use App\Account\Domain\AccountException;
use App\Acl\Application\AclFacade;
use App\Shared\Domain\SecretGenerator;

/**
 * @responsibility Exchanges a valid refresh token for a fresh session.
 */
final readonly class RefreshSessionService
{
    public function __construct(
        private RotateRefreshTokenService $rotate,
        private AccessTokenIssuer $accessTokens,
        private AclFacade $acl,
        private SecretGenerator $secrets,
        private int $refreshTtlSeconds = 2_592_000,
    ) {
    }

    public function __invoke(string $presentedToken, ?string $ipAddress, ?string $userAgent): IssuedSession
    {
        if ('' === $presentedToken) {
            throw AccountException::invalidToken();
        }

        $rotated = ($this->rotate)($presentedToken, $ipAddress, $userAgent);
        $user = $rotated['token']->user();

        if (!$user->status()->canAuthenticate()) {
            // A suspended account must not be able to refresh its way to a fresh access
            // token: the check on login would otherwise be bypassable for 30 days.
            throw AccountException::accountNotActive();
        }

        // Roles and acl_version are re-read on every refresh, so a permission change reaches
        // the client within one access-token lifetime at the very latest — and immediately
        // for anything resolved server-side (ADR-0011).
        $roles = $this->acl->roleNamesOf($user->id());

        return new IssuedSession(
            userId: $user->id()->toRfc4122(),
            email: $user->email(),
            roles: $roles,
            accessToken: $this->accessTokens->issue($user->id(), $user->email(), $roles, $user->aclVersion()),
            accessTtlSeconds: $this->accessTokens->ttlSeconds(),
            refreshToken: $rotated['plaintext'],
            refreshTtlSeconds: $this->refreshTtlSeconds,
            csrfToken: $this->secrets->generate(16),
        );
    }
}
