<?php

declare(strict_types=1);

namespace App\Api\Account;

use App\Account\Application\Service\RegisterUserService;
use App\Api\Account\Request\RegisterRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * POST /api/v1/auth/register.
 *
 * Always 202, whether or not the address was already registered. Anything else makes this an
 * account-enumeration oracle; see RegisterUserService.
 */
#[Route('/api/v1/auth/register', name: 'auth_register', methods: ['POST'])]
#[IsGranted('PUBLIC_ACCESS')]
final class RegisterController
{
    public function __construct(private readonly RegisterUserService $register)
    {
    }

    public function __invoke(#[MapRequestPayload] RegisterRequest $payload): JsonResponse
    {
        ($this->register)($payload->email, $payload->password);

        return new JsonResponse(
            ['message' => 'If that address can be registered, a confirmation email is on its way.'],
            JsonResponse::HTTP_ACCEPTED,
        );
    }
}
