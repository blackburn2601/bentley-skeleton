<?php

declare(strict_types=1);

namespace App\Api\Acl;

use App\Acl\Application\Service\UpdateRoleService;
use App\Api\Acl\Request\UpdateRoleRequest;
use App\Api\Acl\Response\UpdateRoleResponse;
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
 * PATCH /api/v1/admin/roles/{id}.
 */
#[Route(
    '/api/v1/admin/roles/{id}',
    name: 'patch_update_role',
    requirements: ['id' => Requirement::UUID],
    methods: ['PATCH'],
)]
#[IsGranted('role.update')]
#[RateLimit(policy: 'admin_write', keyedBy: 'user')]
final readonly class UpdateRoleController
{
    public function __construct(private UpdateRoleService $updateRole)
    {
    }

    public function __invoke(
        string $id,
        #[CurrentUser]
        AuthenticatedUser $actor,
        #[MapRequestPayload]
        UpdateRoleRequest $request,
    ): JsonResponse {
        $result = ($this->updateRole)(Uuid::fromString($id), $request->description);

        return new JsonResponse(UpdateRoleResponse::from($result));
    }
}
