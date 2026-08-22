<?php

declare(strict_types=1);

namespace App\Api\Account;

use App\Account\Application\Service\RequestPasswordResetService;
use App\Api\Account\Request\ForgotPasswordRequest;
use App\Api\Attribute\RateLimit;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * POST /api/v1/auth/password/forgot.
 *
 * Always 202, whether or not the address has an account. Anything else makes this a
 * membership oracle that needs no credentials to query.
 */
#[Route('/api/v1/auth/password/forgot', name: 'auth_password_forgot', methods: ['POST'])]
#[IsGranted('PUBLIC_ACCESS')]
#[RateLimit('password_reset', keyedBy: 'ip+payload', payloadField: 'email')]
final readonly class ForgotPasswordController
{
    public function __construct(private RequestPasswordResetService $requestReset)
    {
    }

    public function __invoke(#[MapRequestPayload] ForgotPasswordRequest $payload): JsonResponse
    {
        ($this->requestReset)($payload->email);

        return new JsonResponse(
            ['message' => 'If that address has an account, a reset link is on its way.'],
            JsonResponse::HTTP_ACCEPTED,
        );
    }
}
