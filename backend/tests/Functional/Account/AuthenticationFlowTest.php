<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Api\Security\AuthCookies;
use App\Tests\Functional\ApiTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;

/**
 * The authentication flow, through the real kernel.
 *
 * These assertions are about *behaviour a client depends on*, and about the security
 * properties that are easy to break without noticing — the anti-enumeration responses above
 * all, which look like ordinary error handling and stop working the moment someone "improves"
 * an error message.
 *
 * No #[CoversNothing] here, deliberately. It reads as "this test targets no particular
 * class", but what it actually tells PHPUnit is "record no coverage from this test" — which
 * silently zeroed the entire functional suite's contribution to the coverage report, and made
 * the coverage gate measure a fraction of what the tests actually exercise.
 */
final class AuthenticationFlowTest extends ApiTestCase
{
    public function testAnonymousCallersAreRefusedWithProblemJson(): void
    {
        $this->json('GET', '/api/v1/auth/me');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame(401, $this->responseJson()['status'] ?? null);
    }

    public function testLoginIssuesHttpOnlyHostPrefixedCookies(): void
    {
        $user = $this->createUser('login');
        $this->logIn($user);

        // Asserted against the raw Set-Cookie headers, not the BrowserKit cookie jar.
        //
        // BrowserKit deliberately DISCARDS the `secure` flag when the request URI is not
        // https (Cookie::fromString), so the jar reports secure=false for a cookie the server
        // correctly marked secure. Testing the jar would therefore assert the behaviour of a
        // test double; testing the header asserts what a browser actually receives, which is
        // the property that matters.
        // headers->all() is list<string|null>; the nulls cannot occur for set-cookie, but
        // narrowing here is cheaper than asserting it three times below.
        $setCookies = array_values(array_filter(
            $this->client->getResponse()->headers->all('set-cookie'),
            static fn (?string $header): bool => null !== $header,
        ));

        foreach ([AuthCookies::ACCESS, AuthCookies::REFRESH] as $name) {
            $header = $this->setCookieFor($setCookies, $name);

            self::assertNotNull($header, \sprintf('%s cookie was not set.', $name));
            self::assertStringContainsString('httponly', strtolower($header), \sprintf(
                '%s must be HttpOnly — a token readable by script is a token stealable by injected script.',
                $name,
            ));
            self::assertStringContainsString('secure', strtolower($header), \sprintf(
                '%s must be Secure; a bearer cookie sent over plain HTTP is a credential leak.',
                $name,
            ));
            self::assertStringContainsString('samesite=strict', strtolower($header), \sprintf(
                '%s must be SameSite=Strict.',
                $name,
            ));
        }

        // The refresh cookie is scoped, so the long-lived credential is not attached to every
        // ordinary API request.
        $refresh = (string) $this->setCookieFor($setCookies, AuthCookies::REFRESH);
        self::assertStringContainsString('path=/api/v1/auth', strtolower($refresh));

        // The __Host- prefix and a scoped path are mutually exclusive: a __Host- cookie MUST use
        // Path=/, and a browser silently discards one that does not. The refresh cookie needs
        // the scoped path, so it cannot carry __Host- — this is exactly the invariant whose
        // absence let the bug through (a __Host- refresh cookie was never stored, so refresh
        // always 401'd and sessions died at 10-min idle; ADR-0031). The access cookie is
        // Path=/, so it keeps __Host- and its subdomain-fixation protection. This guard goes red
        // if __Host- is ever re-added to the refresh cookie name.
        self::assertStringStartsWith('__Host-', (string) $this->setCookieFor($setCookies, AuthCookies::ACCESS), 'The access cookie is Path=/, so it must carry __Host- for its subdomain-fixation protection.');
        self::assertStringStartsNotWith('__Host-', $refresh, \sprintf(
            '%s must not carry the __Host- prefix: __Host- requires Path=/, but the refresh cookie is scoped to /api/v1/auth. A browser discards a __Host- cookie with a non-root path, which silently breaks refresh.',
            AuthCookies::REFRESH,
        ));

        // The CSRF value is deliberately readable: the SPA has to echo it in a header, and
        // possession of it alone proves nothing without the HttpOnly cookies.
        $csrf = (string) $this->setCookieFor($setCookies, AuthCookies::CSRF);
        self::assertStringNotContainsString('httponly', strtolower($csrf), 'The CSRF cookie must be readable by JavaScript; that is how double-submit works.');
    }

    public function testTokensNeverAppearInTheResponseBody(): void
    {
        $user = $this->createUser('body');
        $this->logIn($user);

        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('eyJ', $body, 'A JWT in the response body defeats the point of HttpOnly cookies.');
        self::assertArrayNotHasKey('token', $this->responseJson());
        self::assertArrayNotHasKey('accessToken', $this->responseJson());
    }

    public function testMeReturnsTheCallerWithTheirEffectivePermissions(): void
    {
        $user = $this->createUser('me');
        $this->logIn($user);

        $this->json('GET', '/api/v1/auth/me');

        self::assertResponseIsSuccessful();
        $body = $this->responseJson();

        self::assertSame($user->username(), $body['username'] ?? null);

        $permissions = $body['permissions'] ?? [];
        self::assertIsArray($permissions);
        self::assertContains('account.read', $permissions);
    }

    // ------------------------------------------------------------------ anti-enumeration

    public function testWrongPasswordAndUnknownAccountAreIndistinguishable(): void
    {
        $user = $this->createUser('enum');

        $this->json('POST', '/api/v1/auth/login', ['username' => $user->username(), 'password' => 'definitely-not-the-password']);
        $wrongPassword = [$this->client->getResponse()->getStatusCode(), $this->responseJson()['detail'] ?? null];

        $this->json('POST', '/api/v1/auth/login', ['username' => 'no-such-account', 'password' => 'definitely-not-the-password']);
        $unknownAccount = [$this->client->getResponse()->getStatusCode(), $this->responseJson()['detail'] ?? null];

        self::assertSame(
            $wrongPassword,
            $unknownAccount,
            'These must be byte-identical. Any difference turns the login form into an oracle '
            .'for which usernames have accounts here.',
        );
    }

    // ------------------------------------------------------------------ sessions

    public function testRefreshRotatesTheTokenAndReuseKillsTheFamily(): void
    {
        $user = $this->createUser('rotate');
        $this->logIn($user);

        $original = $this->currentRefreshToken();
        $csrf = $this->currentCsrfToken();

        $this->refreshWith($original, $csrf);
        self::assertResponseStatusCodeSame(204);

        $rotated = $this->currentRefreshToken();
        self::assertNotSame($original, $rotated, 'The refresh token must rotate on every use.');

        // Replay the original. This is the theft signal: whoever refreshes second presents a
        // token that has already been used.
        $this->refreshWith($original, $csrf);
        self::assertResponseStatusCodeSame(401, 'An already-rotated token must be refused.');

        // And the successor must be dead too. We cannot tell the thief from the victim, so
        // the entire family is revoked — logging both out, which is recoverable, rather than
        // leaving a live stolen token in circulation, which is not.
        $this->refreshWith($rotated, $csrf);
        self::assertResponseStatusCodeSame(
            401,
            'Reuse must revoke the whole family, not only the token that was replayed.',
        );
    }

    public function testLogoutSucceedsEvenWithoutASession(): void
    {
        $this->json('POST', '/api/v1/auth/logout');

        self::assertResponseStatusCodeSame(204, 'Logout must always clear cookies; refusing would strand a client that cannot get rid of them.');
    }

    /**
     * Send a refresh request with an EXACT cookie value.
     *
     * The cookie jar is cleared and repopulated rather than mutated: after a rotation the jar
     * holds the server's new cookie, and setting another one with the same name under a
     * different domain key leaves two candidates with no guarantee about which is sent. For a
     * test whose entire point is *which* token was presented, that ambiguity is fatal.
     */
    private function refreshWith(string $refreshToken, string $csrfToken): void
    {
        $jar = $this->client->getCookieJar();
        $jar->clear();
        $jar->set(new BrowserKitCookie(AuthCookies::REFRESH, $refreshToken, null, '/api/v1/auth', 'localhost'));
        $jar->set(new BrowserKitCookie(AuthCookies::CSRF, $csrfToken, null, '/', 'localhost'));

        $this->json('POST', '/api/v1/auth/refresh', headers: [
            'HTTP_'.str_replace('-', '_', strtoupper(AuthCookies::CSRF_HEADER)) => $csrfToken,
        ]);
    }

    private function currentRefreshToken(): string
    {
        $cookie = $this->client->getCookieJar()->get(AuthCookies::REFRESH, '/api/v1/auth');
        self::assertNotNull($cookie, 'No refresh cookie was set.');

        return $cookie->getValue();
    }

    private function currentCsrfToken(): string
    {
        $cookie = $this->client->getCookieJar()->get(AuthCookies::CSRF);
        self::assertNotNull($cookie, 'No CSRF cookie was set.');

        return $cookie->getValue();
    }

    /**
     * @param list<string> $setCookies
     */
    private function setCookieFor(array $setCookies, string $name): ?string
    {
        foreach ($setCookies as $header) {
            if (str_starts_with($header, $name.'=')) {
                return $header;
            }
        }

        return null;
    }
}
