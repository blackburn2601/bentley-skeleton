<?php

declare(strict_types=1);

namespace App\Api\Account;

use App\Account\Application\Service\UseTwoFactorRecoveryService;
use App\Api\Account\Request\UseTwoFactorRecoveryRequest;
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
 * POST /api/v1/auth/mfa/recovery/verify.
 *
 * The fallback path: the caller lost their authenticator and types a one-time recovery code.
 * On a valid, unused code the challenge completes into a full session, just like the TOTP
 * verify. The burn is audited at high severity (ADR-0026).
 */
#[Route('/api/v1/auth/mfa/recovery/verify', name: 'post_use_two_factor_recovery', methods: ['POST'])]
#[IsGranted('MFA_PENDING')]
#[RateLimit('recovery_verify', keyedBy: 'user')]
final readonly class UseTwoFactorRecoveryController
{
    public function __construct(
        private UseTwoFactorRecoveryService $useTwoFactorRecovery,
        private AuthCookies $cookies,
    ) {
    }

    public function __invoke(
        #[CurrentUser]
        AuthenticatedUser $actor,
        #[MapRequestPayload]
        UseTwoFactorRecoveryRequest $payload,
        Request $request,
    ): JsonResponse {
        $result = ($this->useTwoFactorRecovery)(
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
