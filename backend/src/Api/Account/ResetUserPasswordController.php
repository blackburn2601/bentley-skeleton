<?php

declare(strict_types=1);

namespace App\Api\Account;

use App\Account\Application\Service\ResetUserPasswordService;
use App\Api\Account\Response\ResetUserPasswordResponse;
use App\Api\Attribute\RateLimit;
use App\Api\Security\AuthenticatedUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * POST /api/v1/admin/users/{id}/password.
 *
 * An administrator resets a user's password to a new system-generated temporary one, shown
 * once, and every existing session for that user is revoked immediately (ADR-0024).
 */
#[Route(
    '/api/v1/admin/users/{id}/password',
    name: 'post_reset_user_password',
    requirements: ['id' => Requirement::UUID],
    methods: ['POST'],
)]
#[IsGranted('user.update')]
#[RateLimit(policy: 'admin_write', keyedBy: 'user')]
final readonly class ResetUserPasswordController
{
    public function __construct(private ResetUserPasswordService $resetUserPassword)
    {
    }

    public function __invoke(string $id, #[CurrentUser] AuthenticatedUser $actor): JsonResponse
    {
        $created = ($this->resetUserPassword)(Uuid::fromString($id), $actor->id());

        return new JsonResponse(ResetUserPasswordResponse::from($created));
    }
}
