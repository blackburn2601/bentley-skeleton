<?php

declare(strict_types=1);

namespace App\Api\Account;

use App\Account\Application\Service\RefreshSessionService;
use App\Api\Security\AuthCookies;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * POST /api/v1/auth/refresh.
 *
 * Public because the access token has, by definition, expired by the time this is called —
 * the refresh cookie is the credential. CSRF double-submit applies, and reuse detection is
 * what makes a stolen refresh token detectable (ADR-0002).
 */
#[Route('/api/v1/auth/refresh', name: 'auth_refresh', methods: ['POST'])]
#[IsGranted('PUBLIC_ACCESS')]
final class RefreshController
{
    public function __construct(
        private readonly RefreshSessionService $refresh,
        private readonly AuthCookies $cookies,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $presented = $request->cookies->get(AuthCookies::REFRESH);

        $session = ($this->refresh)(
            \is_string($presented) ? $presented : '',
            $request->getClientIp(),
            $request->headers->get('User-Agent'),
        );

        $response = new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
        $response->headers->setCookie($this->cookies->access($session->accessToken, $session->accessTtlSeconds));
        $response->headers->setCookie($this->cookies->refresh($session->refreshToken, $session->refreshTtlSeconds));
        $response->headers->setCookie($this->cookies->csrf($session->csrfToken, $session->refreshTtlSeconds));

        return $response;
    }
}
