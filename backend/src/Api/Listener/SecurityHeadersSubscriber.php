<?php

declare(strict_types=1);

namespace App\Api\Listener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * The response headers that make a browser defend the user for us.
 *
 * Every one of these is set because a browser default is permissive. They cost nothing at
 * runtime and are checked by a snapshot test, so removing one is a visible act rather than a
 * quiet regression.
 */
#[AsEventListener(event: ResponseEvent::class, priority: -128)]
final readonly class SecurityHeadersSubscriber
{
    public function __construct(private bool $hstsEnabled = true)
    {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;

        // Stops a browser from second-guessing Content-Type. Without it, a JSON response
        // containing attacker-controlled text can be sniffed as HTML and executed.
        $headers->set('X-Content-Type-Options', 'nosniff');

        // DENY, not SAMEORIGIN: this is a JSON API and an SPA. Nothing here should ever be
        // framed, and clickjacking defences are worth having even where they seem unnecessary.
        $headers->set('X-Frame-Options', 'DENY');

        // Send the origin to other sites, the full path to our own. Prevents API paths — which
        // routinely contain object ids — leaking into third-party analytics via Referer.
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Turn off capabilities this application never uses. If it is not requested, it cannot
        // be abused by injected script.
        $headers->set(
            'Permissions-Policy',
            'accelerometer=(), autoplay=(), camera=(), display-capture=(), encrypted-media=(), '
            .'fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), '
            .'midi=(), payment=(), usb=(), xr-spatial-tracking=()',
        );

        // Cross-origin isolation. COOP severs the window.opener relationship so a page we open
        // (or that opens us) cannot reach into this browsing context; CORP stops other origins
        // embedding our responses as subresources.
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        // HSTS. Off by default in development, where there is no TLS — setting it on
        // http://localhost would pin the browser to https for the whole domain, and the
        // browser remembers that long after you have stopped wanting it.
        if ($this->hstsEnabled) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        // A JSON API renders nothing, so the strictest possible policy applies. The SPA's own
        // index.html gets a nonce-based policy from the web server, where the nonce can be
        // generated per response.
        if ($this->isApiResponse($event)) {
            $headers->set(
                'Content-Security-Policy',
                "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
            );
        }

        // Error and auth responses must never be cached — by the browser or by anything in
        // between. A cached 403 for one user is a 403 for the next.
        if ($event->getResponse()->isClientError() || $event->getResponse()->isServerError()) {
            $headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        }
    }

    private function isApiResponse(ResponseEvent $event): bool
    {
        return str_starts_with($event->getRequest()->getPathInfo(), '/api/')
            || str_starts_with($event->getRequest()->getPathInfo(), '/health/');
    }
}
