<?php

declare(strict_types=1);

namespace App\Api\Account;

use App\Account\Application\Service\ListActiveSessionsService;
use App\Api\Account\Response\SessionListResponse;
use App\Api\Security\AuthCookies;
use App\Api\Security\AuthenticatedUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * GET /api/v1/auth/sessions.
 *
 * "Where am I signed in?" — and the screen someone uses when they suspect they are not the
 * only one.
 */
#[Route('/api/v1/auth/sessions', name: 'auth_sessions', methods: ['GET'])]
#[IsGranted('account.read')]
final readonly class ListSessionsController
{
    public function __construct(private ListActiveSessionsService $listSessions)
    {
    }

    public function __invoke(#[CurrentUser] AuthenticatedUser $user, Request $request): JsonResponse
    {
        $current = $request->cookies->get(AuthCookies::REFRESH);

        return new JsonResponse(SessionListResponse::from(
            ($this->listSessions)($user->id(), \is_string($current) ? $current : null),
        ));
    }
}
