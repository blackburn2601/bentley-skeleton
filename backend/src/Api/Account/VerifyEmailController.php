<?php

declare(strict_types=1);

namespace App\Api\Account;

use App\Account\Application\Service\VerifyEmailService;
use App\Api\Account\Request\TokenRequest;
use App\Api\Attribute\RateLimit;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * POST /api/v1/auth/verify-email.
 *
 * POST rather than a GET on the emailed link: mail clients and security scanners routinely
 * prefetch links, and a GET that mutates state would be "verified" before the user clicked.
 * The link opens the SPA, which posts the token.
 */
#[Route('/api/v1/auth/verify-email', name: 'auth_verify_email', methods: ['POST'])]
#[IsGranted('PUBLIC_ACCESS')]
#[RateLimit('verify_resend')]
final class VerifyEmailController
{
    public function __construct(private readonly VerifyEmailService $verify)
    {
    }

    public function __invoke(#[MapRequestPayload] TokenRequest $payload): JsonResponse
    {
        ($this->verify)($payload->token);

        return new JsonResponse(['message' => 'Your email address is confirmed. You can sign in now.']);
    }
}
