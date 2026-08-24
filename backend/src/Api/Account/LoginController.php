<?php

declare(strict_types=1);

namespace App\Api\Account;

use App\Account\Application\IssuedSession;
use App\Account\Application\Service\SignInService;
use App\Account\Application\TwoFactorChallenge;
use App\Api\Account\Request\LoginRequest;
use App\Api\Account\Response\SessionResponse;
use App\Api\Attribute\RateLimit;
use App\Api\Security\AuthCookies;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * POST /api/v1/auth/login.
 *
 * The tokens leave in HttpOnly cookies, never in the body (ADR-0002). When a second factor is
 * owed, only the access cookie is set — no refresh cookie is issued before MFA verifies
 * (ADR-0026), so a stolen refresh cookie cannot bypass the second factor for 30 days.
 */
#[Route('/api/v1/auth/login', name: 'auth_login', methods: ['POST'])]
#[IsGranted('PUBLIC_ACCESS')]
#[RateLimit('login', keyedBy: 'ip+payload', payloadField: 'username')]
final readonly class LoginController
{
    public function __construct(
        private SignInService $signIn,
        private AuthCookies $cookies,
    ) {
    }

    public function __invoke(
        #[MapRequestPayload]
        LoginRequest $payload,
        Request $request,
    ): JsonResponse {
        $result = ($this->signIn)(
            $payload->username,
            $payload->password,
            $request->getClientIp(),
            $request->headers->get('User-Agent'),
        );

        // Mapping the service result to HTTP — which cookies, which body shape — is the
        // controller's job. Which outcome occurs is the service's.
        if ($result instanceof IssuedSession) {
            $response = new JsonResponse(
                SessionResponse::authenticated($result->userId, $result->username, $result->roles, false),
            );

            $response->headers->setCookie($this->cookies->access($result->accessToken, $result->accessTtlSeconds));
            $response->headers->setCookie($this->cookies->refresh($result->refreshToken, $result->refreshTtlSeconds));
            $response->headers->setCookie($this->cookies->csrf($result->csrfToken, $result->refreshTtlSeconds));

            return $response;
        }

        /** @var TwoFactorChallenge $result */
        $response = new JsonResponse(
            SessionResponse::pending($result->userId, $result->username),
        );

        // Only the access cookie, scoped to the whole site like a normal access token so the
        // verify endpoints receive it. No refresh cookie, no CSRF cookie: neither is needed
        // before the second factor, and issuing them would create credentials that outlive
        // the challenge.
        $response->headers->setCookie($this->cookies->access($result->accessToken, $result->accessTtlSeconds));

        return $response;
    }
}
