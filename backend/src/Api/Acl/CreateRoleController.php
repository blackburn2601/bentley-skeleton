<?php

declare(strict_types=1);

namespace App\Api\Acl;

use App\Acl\Application\Service\CreateRoleService;
use App\Api\Acl\Request\CreateRoleRequest;
use App\Api\Acl\Response\CreateRoleResponse;
use App\Api\Attribute\RateLimit;
use App\Api\Security\AuthenticatedUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * POST /api/v1/admin/roles.
 */
#[Route(
    '/api/v1/admin/roles',
    name: 'post_create_role',
    methods: ['POST'],
)]
#[IsGranted('role.create')]
#[RateLimit(policy: 'admin_write', keyedBy: 'user')]
final readonly class CreateRoleController
{
    public function __construct(private CreateRoleService $createRole)
    {
    }

    public function __invoke(
        #[CurrentUser]
        AuthenticatedUser $actor,
        #[MapRequestPayload]
        CreateRoleRequest $request,
    ): JsonResponse {
        $result = ($this->createRole)($request->name, $request->description, $actor->id());

        return new JsonResponse(CreateRoleResponse::from($result), JsonResponse::HTTP_CREATED);
    }
}
