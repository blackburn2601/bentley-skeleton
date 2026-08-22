<?php

declare(strict_types=1);

namespace App\Api\Account;

use App\Account\Application\Service\SignInService;
use App\Api\Account\Request\LoginRequest;
use App\Api\Account\Response\SessionResponse;
use App\Api\Security\AuthCookies;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * POST /api/v1/auth/login.
 *
 * The tokens leave in HttpOnly cookies, never in the body (ADR-0002).
 */
#[Route('/api/v1/auth/login', name: 'auth_login', methods: ['POST'])]
#[IsGranted('PUBLIC_ACCESS')]
final class LoginController
{
    public function __construct(
        private readonly SignInService $signIn,
        private readonly AuthCookies $cookies,
    ) {
    }

    public function __invoke(
        #[MapRequestPayload]
        LoginRequest $payload,
        Request $request,
    ): JsonResponse {
        $session = ($this->signIn)(
            $payload->email,
            $payload->password,
            $request->getClientIp(),
            $request->headers->get('User-Agent'),
        );

        $response = new JsonResponse(
            SessionResponse::authenticated($session->userId, $session->email, $session->roles),
        );

        $response->headers->setCookie($this->cookies->access($session->accessToken, $session->accessTtlSeconds));
        $response->headers->setCookie($this->cookies->refresh($session->refreshToken, $session->refreshTtlSeconds));
        $response->headers->setCookie($this->cookies->csrf($session->csrfToken, $session->refreshTtlSeconds));

        return $response;
    }
}
