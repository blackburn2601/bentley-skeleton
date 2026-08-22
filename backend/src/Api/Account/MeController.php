<?php

declare(strict_types=1);

namespace App\Api\Account;

use App\Account\Application\Service\DescribeCurrentUserService;
use App\Api\Account\Response\MeResponse;
use App\Api\Security\AuthenticatedUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * GET /api/v1/auth/me.
 *
 * What the SPA calls on boot to find out who it is talking for.
 */
#[Route('/api/v1/auth/me', name: 'auth_me', methods: ['GET'])]
#[IsGranted('account.read')]
final readonly class MeController
{
    public function __construct(private DescribeCurrentUserService $describe)
    {
    }

    public function __invoke(#[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $profile = ($this->describe)($user->id());

        return new JsonResponse(MeResponse::from(
            $profile['id'],
            $profile['email'],
            $profile['emailVerified'],
            $profile['mfaEnabled'],
            $profile['roles'],
            $profile['permissions'],
        ));
    }
}
