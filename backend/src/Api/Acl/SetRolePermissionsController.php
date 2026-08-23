<?php

declare(strict_types=1);

namespace App\Api\Acl;

use App\Acl\Application\Service\SetRolePermissionsService;
use App\Api\Acl\Request\SetRolePermissionsRequest;
use App\Api\Acl\Response\SetRolePermissionsResponse;
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
 * PUT /api/v1/admin/roles/{id}/permissions.
 */
#[Route(
    '/api/v1/admin/roles/{id}/permissions',
    name: 'put_set_role_permissions',
    requirements: ['id' => Requirement::UUID],
    methods: ['PUT'],
)]
#[IsGranted('permission.grant')]
#[RateLimit(policy: 'admin_write', keyedBy: 'user')]
final readonly class SetRolePermissionsController
{
    public function __construct(private SetRolePermissionsService $setRolePermissions)
    {
    }

    public function __invoke(
        string $id,
        #[CurrentUser]
        AuthenticatedUser $actor,
        #[MapRequestPayload]
        SetRolePermissionsRequest $request,
    ): JsonResponse {
        $result = ($this->setRolePermissions)(Uuid::fromString($id), array_values($request->permissions), $actor->id());

        return new JsonResponse(SetRolePermissionsResponse::from($result));
    }
}
