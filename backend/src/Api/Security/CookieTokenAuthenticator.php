<?php

declare(strict_types=1);

namespace App\Api\Security;

use App\Account\Application\AccessTokenIssuer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Authenticates a request from the access-token cookie.
 *
 * Self-validating: the JWT signature *is* the proof, so there is no database read on the
 * authentication path. Everything the security layer needs comes from the claims, and
 * anything else — permissions above all — is resolved separately (ADR-0011).
 *
 * A Bearer header is accepted too, behind a flag, for machine clients that cannot hold
 * cookies. It is off by default: with it on, any endpoint reachable by a browser becomes
 * reachable by a cross-origin script that has obtained a token, and the CSRF protection the
 * cookie scheme provides no longer applies.
 */
final class CookieTokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly AccessTokenIssuer $tokens,
        private readonly bool $allowBearerHeader = false,
    ) {
    }

    public function supports(Request $request): bool
    {
        return null !== $this->extractToken($request);
    }

    public function authenticate(Request $request): Passport
    {
        $token = $this->extractToken($request);

        if (null === $token) {
            throw new AuthenticationException('No access token.');
        }

        $claims = $this->tokens->decode($token);

        if (null === $claims) {
            throw new AuthenticationException('The access token is invalid or has expired.');
        }

        $user = new AuthenticatedUser(
            Uuid::fromString($claims['sub']),
            $claims['email'],
            $claims['roles'],
            $claims['perm_v'],
        );

        // The identifier callback returns the user we already built: there is no user
        // provider round trip, which is the point of a stateless token.
        return new SelfValidatingPassport(
            new UserBadge($user->getUserIdentifier(), static fn (): AuthenticatedUser => $user),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    /**
     * Also the firewall's entry point: what an unauthenticated request to a protected
     * endpoint receives. A JSON API answers 401 in the same problem+json shape as everything
     * else — there is nowhere to redirect to.
     */
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->unauthorized();
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->unauthorized();
    }

    private function unauthorized(): Response
    {
        // The exception message is deliberately not echoed: "signature invalid" versus
        // "expired" tells a prober which of the two they achieved.
        return new JsonResponse(
            [
                'type' => 'https://datatracker.ietf.org/doc/html/rfc9457',
                'title' => 'Unauthorized',
                'status' => Response::HTTP_UNAUTHORIZED,
                'detail' => 'Authentication is required to access this resource.',
            ],
            Response::HTTP_UNAUTHORIZED,
            ['Content-Type' => 'application/problem+json'],
        );
    }

    private function extractToken(Request $request): ?string
    {
        $cookie = $request->cookies->get(AuthCookies::ACCESS);

        if (\is_string($cookie) && '' !== $cookie) {
            return $cookie;
        }

        if (!$this->allowBearerHeader) {
            return null;
        }

        $header = $request->headers->get('Authorization');

        if (\is_string($header) && str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        return null;
    }
}
