<?php

declare(strict_types=1);

namespace App\Api\Listener;

use App\Api\Security\AuthCookies;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Double-submit CSRF protection for the cookie-authenticated endpoints.
 *
 * Cookie authentication means the browser attaches credentials to cross-site requests
 * automatically — that is the whole CSRF problem. The defence: a value that exists both in a
 * JavaScript-readable cookie and in a request header. Another origin can *cause* our cookies
 * to be sent, but the same-origin policy stops it *reading* them, so it cannot set the header.
 *
 * SameSite=Strict already blocks the classic attack; this is the second lock. SameSite has
 * had bypasses, is relaxed by some browsers for top-level navigations, and is a single
 * attribute one careless change can weaken.
 *
 * Applied only to the auth endpoints that act on the refresh cookie. Everything else
 * authenticates with the access token, which a cross-site request cannot mint — and the ACL
 * still gates each one.
 */
#[AsEventListener(event: RequestEvent::class, priority: 8)]
final readonly class CsrfDoubleSubmitSubscriber
{
    /** Paths where a cookie alone is sufficient to act, so a second proof is required. */
    private const array PROTECTED_PATHS = [
        '/api/v1/auth/refresh',
        '/api/v1/auth/logout',
        '/api/v1/auth/logout-all',
    ];

    public function __construct(private bool $enabled = true)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$this->enabled || !$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$this->isProtected($request)) {
            return;
        }

        if (!$this->tokensMatch($request)) {
            $event->setResponse($this->refuse($request->getPathInfo()));
        }
    }

    private function tokensMatch(Request $request): bool
    {
        $cookie = $request->cookies->get(AuthCookies::CSRF);

        // A missing cookie means no session was ever established, which the endpoint itself
        // rejects far more informatively. Only a MISMATCH is treated as an attack.
        if (!\is_string($cookie) || '' === $cookie) {
            return true;
        }

        $header = $request->headers->get(AuthCookies::CSRF_HEADER);

        return \is_string($header) && hash_equals($cookie, $header);
    }

    private function isProtected(Request $request): bool
    {
        // Safe methods do not act, so they need no CSRF token — and requiring one on GET
        // breaks ordinary navigation for no benefit.
        if ($request->isMethodSafe()) {
            return false;
        }

        return \in_array($request->getPathInfo(), self::PROTECTED_PATHS, true);
    }

    private function refuse(string $instance): JsonResponse
    {
        $response = new JsonResponse([
            'type' => 'https://datatracker.ietf.org/doc/html/rfc9457',
            'title' => 'Forbidden',
            'status' => Response::HTTP_FORBIDDEN,
            'detail' => \sprintf(
                'This request is missing or has an incorrect %s header. Send the value of the '
                .'%s cookie in that header.',
                AuthCookies::CSRF_HEADER,
                AuthCookies::CSRF,
            ),
            'instance' => $instance,
        ], Response::HTTP_FORBIDDEN);

        $response->headers->set('Content-Type', 'application/problem+json');

        return $response;
    }
}
