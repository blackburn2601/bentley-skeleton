<?php

declare(strict_types=1);

namespace App\Api\Account;

use App\Account\Application\Service\UpdateUserService;
use App\Api\Account\Request\UpdateUserRequest;
use App\Api\Account\Response\UpdateUserResponse;
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
 * PATCH /api/v1/admin/users/{id}.
 */
#[Route(
    '/api/v1/admin/users/{id}',
    name: 'patch_update_user',
    requirements: ['id' => Requirement::UUID],
    methods: ['PATCH'],
)]
#[IsGranted('user.update')]
#[RateLimit(policy: 'admin_write', keyedBy: 'user')]
final readonly class UpdateUserController
{
    public function __construct(private UpdateUserService $updateUser)
    {
    }

    public function __invoke(
        string $id,
        #[CurrentUser]
        AuthenticatedUser $actor,
        #[MapRequestPayload]
        UpdateUserRequest $request,
    ): JsonResponse {
        $user = ($this->updateUser)(Uuid::fromString($id), $request->email, $actor->id());

        return new JsonResponse(UpdateUserResponse::from($user));
    }
}
