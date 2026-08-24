<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Application\AccessTokenIssuer;
use App\Account\Application\IssuedSession;
use App\Acl\Application\AclFacade;
use App\Audit\Application\AuditFacade;
use App\Shared\Domain\SecretGenerator;
use App\Shared\Domain\SecurityEventType;

/**
 * @responsibility Turns valid credentials into an authenticated session.
 */
final readonly class SignInService
{
    public function __construct(
        private AuthenticateUserService $authenticate,
        private StartSessionService $startSession,
        private AccessTokenIssuer $accessTokens,
        private AclFacade $acl,
        private AuditFacade $audit,
        private SecretGenerator $secrets,
        private int $refreshTtlSeconds = 2_592_000,
    ) {
    }

    public function __invoke(string $username, string $password, ?string $ipAddress, ?string $userAgent): IssuedSession
    {
        $user = ($this->authenticate)($username, $password);

        // Roles come from the Acl context through its facade (INV-02) — Account does not own
        // them and must not read acl tables directly.
        $roles = $this->acl->roleNamesOf($user->id());

        $session = ($this->startSession)($user, $ipAddress, $userAgent);

        $accessToken = $this->accessTokens->issue(
            $user->id(),
            $user->username(),
            $roles,
            $user->aclVersion(),
        );

        $this->audit->record(SecurityEventType::LoginSucceeded, $user->id());

        return new IssuedSession(
            userId: $user->id()->toRfc4122(),
            username: $user->username(),
            roles: $roles,
            accessToken: $accessToken,
            accessTtlSeconds: $this->accessTokens->ttlSeconds(),
            refreshToken: $session['plaintext'],
            refreshTtlSeconds: $this->refreshTtlSeconds,
            // The double-submit CSRF value. Cookie auth means the browser attaches
            // credentials to cross-site requests automatically; requiring this same value in
            // a header proves the request came from our own origin, since another site can
            // cause the cookie to be sent but cannot read it to set the header.
            csrfToken: $this->secrets->generate(16),
        );
    }
}
