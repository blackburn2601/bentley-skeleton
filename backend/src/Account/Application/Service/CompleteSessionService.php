<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Application\AccessTokenIssuer;
use App\Account\Application\IssuedSession;
use App\Account\Domain\User;
use App\Acl\Application\AclFacade;
use App\Shared\Domain\SecretGenerator;

/**
 * @responsibility Mints a full authenticated session for a user who just proved a factor.
 *
 * The shared tail of every login path — password-only, TOTP verify and recovery-code verify —
 * so the access token, the refresh-token family and the CSRF value are assembled in one place
 * and the cookie TTL cannot drift from the token TTL across three copy-pasted call sites. The
 * `amr` the caller passes (`['totp']` after a second factor, `[]` for a floor user) is carried on
 * the access token and the refresh-token row, so refresh never re-challenges (ADR-0026).
 */
final readonly class CompleteSessionService
{
    public function __construct(
        private StartSessionService $startSession,
        private AccessTokenIssuer $accessTokens,
        private AclFacade $acl,
        private SecretGenerator $secrets,
        private int $refreshTtlSeconds = 2_592_000,
    ) {
    }

    /**
     * @param list<string> $amr how the session authenticated (ADR-0026)
     */
    public function __invoke(User $user, array $amr, ?string $ipAddress, ?string $userAgent): IssuedSession
    {
        // Roles come from the Acl context through its facade (INV-02) — Account does not own
        // them and must not read acl tables directly.
        $roles = $this->acl->roleNamesOf($user->id());
        $session = ($this->startSession)($user, $ipAddress, $userAgent, $amr);

        $accessToken = $this->accessTokens->issue(
            $user->id(),
            $user->username(),
            $roles,
            $user->aclVersion(),
            $amr,
        );

        return new IssuedSession(
            userId: $user->id()->toRfc4122(),
            username: $user->username(),
            roles: $roles,
            accessToken: $accessToken,
            accessTtlSeconds: $this->accessTokens->ttlSeconds(),
            refreshToken: $session['plaintext'],
            refreshTtlSeconds: $this->refreshTtlSeconds,
            // The double-submit CSRF value. Cookie auth means the browser attaches credentials
            // to cross-site requests automatically; requiring this value in a header proves the
            // request came from our own origin, since another site can send the cookie but not
            // read it to set the header.
            csrfToken: $this->secrets->generate(16),
        );
    }
}
