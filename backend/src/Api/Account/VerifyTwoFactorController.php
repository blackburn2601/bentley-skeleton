<?php

declare(strict_types=1);

namespace App\Api\Account;

use App\Account\Application\Service\VerifyTwoFactorService;
use App\Api\Account\Request\VerifyTwoFactorRequest;
use App\Api\Account\Response\SessionResponse;
use App\Api\Attribute\RateLimit;
use App\Api\Security\AuthCookies;
use App\Api\Security\AuthenticatedUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * POST /api/v1/auth/mfa/verify.
 *
 * Completes the second factor and turns the half-authenticated challenge into a full session.
 * The tokens leave in HttpOnly cookies, never in the body (ADR-0002); the body is the same
 * `authenticated` session shape as login. No CSRF cookie was set during the challenge, and the
 * double-submit guard treats a missing CSRF cookie as exempt, so the pending caller may POST.
 */
#[Route('/api/v1/auth/mfa/verify', name: 'post_verify_two_factor', methods: ['POST'])]
#[IsGranted('MFA_PENDING')]
#[RateLimit('totp_verify', keyedBy: 'user')]
final readonly class VerifyTwoFactorController
{
    public function __construct(
        private VerifyTwoFactorService $verifyTwoFactor,
        private AuthCookies $cookies,
    ) {
    }

    public function __invoke(
        #[CurrentUser]
        AuthenticatedUser $actor,
        #[MapRequestPayload]
        VerifyTwoFactorRequest $payload,
        Request $request,
    ): JsonResponse {
        $result = ($this->verifyTwoFactor)(
            $actor->id(),
            $payload->code,
            $request->getClientIp(),
            $request->headers->get('User-Agent'),
        );

        $response = new JsonResponse(
            SessionResponse::authenticated($result->userId, $result->username, $result->roles, 'verified'),
        );

        $response->headers->setCookie($this->cookies->access($result->accessToken, $result->accessTtlSeconds));
        $response->headers->setCookie($this->cookies->refresh($result->refreshToken, $result->refreshTtlSeconds));
        $response->headers->setCookie($this->cookies->csrf($result->csrfToken, $result->refreshTtlSeconds));

        return $response;
    }
}
