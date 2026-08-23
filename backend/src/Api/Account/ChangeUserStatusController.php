<?php

declare(strict_types=1);

namespace App\Api\Account;

use App\Account\Application\Service\ChangeUserStatusService;
use App\Account\Domain\UserStatus;
use App\Api\Account\Request\ChangeUserStatusRequest;
use App\Api\Account\Response\ChangeUserStatusResponse;
use App\Api\Attribute\RateLimit;
use App\Api\Security\AuthenticatedUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * PATCH /api/v1/admin/users/{id}/status.
 */
#[Route(
    '/api/v1/admin/users/{id}/status',
    name: 'patch_change_user_status',
    requirements: ['id' => Requirement::UUID],
    methods: ['PATCH'],
)]
#[IsGranted('user.update')]
#[RateLimit(policy: 'admin_write', keyedBy: 'user')]
final readonly class ChangeUserStatusController
{
    public function __construct(private ChangeUserStatusService $changeStatus)
    {
    }

    public function __invoke(
        string $id,
        #[CurrentUser]
        AuthenticatedUser $actor,
        #[MapRequestPayload]
        ChangeUserStatusRequest $request,
    ): JsonResponse {
        $user = ($this->changeStatus)(
            Uuid::fromString($id),
            UserStatus::from($request->status),
            $actor->id(),
        );

        return new JsonResponse(ChangeUserStatusResponse::from($user));
    }
}
