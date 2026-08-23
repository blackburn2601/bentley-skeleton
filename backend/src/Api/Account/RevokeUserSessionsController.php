<?php

declare(strict_types=1);

namespace App\Api\Account;

use App\Account\Application\Service\RevokeUserSessionsService;
use App\Api\Account\Response\RevokeUserSessionsResponse;
use App\Api\Attribute\RateLimit;
use App\Api\Security\AuthenticatedUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * POST /api/v1/admin/users/{id}/sessions/revoke.
 *
 * Signs someone out everywhere without touching their account — the answer to a stolen laptop
 * that does not also lock them out.
 */
#[Route(
    '/api/v1/admin/users/{id}/sessions/revoke',
    name: 'post_revoke_user_sessions',
    requirements: ['id' => Requirement::UUID],
    methods: ['POST'],
)]
#[IsGranted('user.update')]
#[RateLimit(policy: 'admin_write', keyedBy: 'user')]
final readonly class RevokeUserSessionsController
{
    public function __construct(private RevokeUserSessionsService $revokeSessions)
    {
    }

    public function __invoke(string $id, #[CurrentUser] AuthenticatedUser $actor): JsonResponse
    {
        $revoked = ($this->revokeSessions)(Uuid::fromString($id), $actor->id());

        return new JsonResponse(RevokeUserSessionsResponse::from($revoked));
    }
}
