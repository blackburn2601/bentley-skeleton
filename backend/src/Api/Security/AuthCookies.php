<?php

declare(strict_types=1);

namespace App\Api\Security;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * Builds and clears the authentication cookies (ADR-0002, ADR-0031).
 *
 * The `__Host-` prefix is the important part for the access and CSRF cookies. A browser only
 * accepts such a cookie when it is `Secure`, has `Path=/` and has **no** `Domain` attribute —
 * which means a sibling subdomain cannot set or overwrite it. That closes cookie-fixation from
 * any other host on the parent domain, and it is enforced by the browser rather than by us
 * remembering.
 *
 * The refresh cookie does NOT carry `__Host-`. `__Host-` forbids any path other than `/`, but
 * the refresh token is deliberately scoped to `/api/v1/auth` so the long-lived credential is
 * absent from every ordinary request. `__Host-` and a scoped path are mutually exclusive; a
 * `__Host-` cookie with a non-root path is silently discarded by the browser, which is exactly
 * the bug that made refresh never work and sessions die at 10-min idle (ADR-0031). The refresh
 * cookie keeps the other `__Host-` guarantees by construction instead — `Secure`, no `Domain`
 * (host-only), `SameSite=Strict` — just not the prefix that would force `Path=/`.
 *
 * The price of the single-origin approach is that the SPA and the API must share an origin.
 * In development the Vite proxy provides that; a genuinely separate SPA domain needs the
 * Bearer-header mode instead.
 */
final readonly class AuthCookies
{
    public const string ACCESS = '__Host-bentley_at';
    public const string REFRESH = 'bentley_rt';

    /**
     * Readable by JavaScript on purpose — it is the double-submit CSRF value, and the SPA has
     * to echo it in a header. It is not a credential: possession alone proves nothing without
     * the HttpOnly cookies.
     */
    public const string CSRF = '__Host-bentley_csrf';

    public const string CSRF_HEADER = 'X-CSRF-Token';

    /** Scoped so the refresh cookie is never sent to ordinary endpoints. */
    private const string REFRESH_PATH = '/api/v1/auth';

    /** @var 'lax'|'none'|'strict' */
    private string $sameSite;

    public function __construct(string $sameSite = Cookie::SAMESITE_STRICT)
    {
        // Narrowed at the boundary rather than trusted: this arrives from an environment
        // variable, and a typo silently downgrading SameSite from Strict to nothing is
        // exactly the kind of misconfiguration that never announces itself.
        $this->sameSite = match (strtolower($sameSite)) {
            Cookie::SAMESITE_LAX => Cookie::SAMESITE_LAX,
            Cookie::SAMESITE_NONE => Cookie::SAMESITE_NONE,
            default => Cookie::SAMESITE_STRICT,
        };
    }

    public function access(string $token, int $ttlSeconds): Cookie
    {
        return $this->build(self::ACCESS, $token, $ttlSeconds, '/', httpOnly: true);
    }

    public function refresh(string $token, int $ttlSeconds): Cookie
    {
        return $this->build(self::REFRESH, $token, $ttlSeconds, self::REFRESH_PATH, httpOnly: true);
    }

    public function csrf(string $token, int $ttlSeconds): Cookie
    {
        return $this->build(self::CSRF, $token, $ttlSeconds, '/', httpOnly: false);
    }

    /**
     * @return list<Cookie>
     */
    public function cleared(): array
    {
        return [
            $this->build(self::ACCESS, '', -1, '/', httpOnly: true),
            $this->build(self::REFRESH, '', -1, self::REFRESH_PATH, httpOnly: true),
            $this->build(self::CSRF, '', -1, '/', httpOnly: false),
        ];
    }

    private function build(string $name, string $value, int $ttlSeconds, string $path, bool $httpOnly): Cookie
    {
        return Cookie::create($name)
            ->withValue($value)
            ->withExpires(0 === $ttlSeconds ? 0 : time() + $ttlSeconds)
            ->withPath($path)
            // __Host- REQUIRES secure. In local development over plain HTTP the browser will
            // reject these, which is why the dev stack proxies through a single origin and
            // why docs/OPERATIONS.md puts TLS termination in front of the app.
            ->withSecure(true)
            ->withHttpOnly($httpOnly)
            ->withSameSite($this->sameSite);
    }
}
