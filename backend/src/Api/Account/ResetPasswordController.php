<?php

declare(strict_types=1);

namespace App\Api\Account;

use App\Account\Application\Service\ResetPasswordService;
use App\Api\Account\Request\ResetPasswordRequest;
use App\Api\Attribute\RateLimit;
use App\Api\Security\AuthCookies;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * POST /api/v1/auth/password/reset.
 *
 * Public because the emailed token IS the credential — the whole point is that the user
 * cannot sign in.
 */
#[Route('/api/v1/auth/password/reset', name: 'auth_password_reset', methods: ['POST'])]
#[IsGranted('PUBLIC_ACCESS')]
#[RateLimit('password_reset', keyedBy: 'ip')]
final readonly class ResetPasswordController
{
    public function __construct(
        private ResetPasswordService $reset,
        private AuthCookies $cookies,
    ) {
    }

    public function __invoke(#[MapRequestPayload] ResetPasswordRequest $payload): JsonResponse
    {
        ($this->reset)($payload->token, $payload->password);

        $response = new JsonResponse(['message' => 'Your password has been changed. Sign in with it.']);

        // The reset revoked every session, including this browser's if it had one. Clearing
        // the cookies here means the client is not left holding credentials that no longer
        // work — which otherwise shows up as unexplained 401s.
        foreach ($this->cookies->cleared() as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }
}
