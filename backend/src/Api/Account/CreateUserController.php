<?php

declare(strict_types=1);

namespace App\Api\Account;

use App\Account\Application\Service\CreateUserService;
use App\Api\Account\Request\CreateUserRequest;
use App\Api\Account\Response\CreateUserResponse;
use App\Api\Attribute\RateLimit;
use App\Api\Security\AuthenticatedUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * POST /api/v1/admin/users.
 */
#[Route('/api/v1/admin/users', name: 'post_create_user', methods: ['POST'])]
#[IsGranted('user.create')]
#[RateLimit(policy: 'admin_write', keyedBy: 'user')]
final readonly class CreateUserController
{
    public function __construct(private CreateUserService $createUser)
    {
    }

    public function __invoke(
        #[CurrentUser]
        AuthenticatedUser $actor,
        #[MapRequestPayload]
        CreateUserRequest $request,
    ): JsonResponse {
        $user = ($this->createUser)($request->email, $actor->id());

        return new JsonResponse(CreateUserResponse::from($user), JsonResponse::HTTP_CREATED);
    }
}
