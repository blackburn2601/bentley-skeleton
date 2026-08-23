<?php

declare(strict_types=1);

namespace App\Api\Acl;

use App\Acl\Application\Service\AssignRoleService;
use App\Api\Acl\Request\AssignRoleRequest;
use App\Api\Acl\Response\AssignRoleResponse;
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
 * POST /api/v1/admin/users/{id}/roles.
 *
 * The id requirement is a UUID pattern so a malformed one 404s at routing, rather than
 * reaching Doctrine and failing as a 500.
 */
#[Route(
    '/api/v1/admin/users/{id}/roles',
    name: 'post_assign_role',
    requirements: ['id' => Requirement::UUID],
    methods: ['POST'],
)]
#[IsGranted('permission.grant')]
#[RateLimit(policy: 'admin_write', keyedBy: 'user')]
final readonly class AssignRoleController
{
    public function __construct(private AssignRoleService $assignRole)
    {
    }

    public function __invoke(
        string $id,
        #[CurrentUser]
        AuthenticatedUser $user,
        #[MapRequestPayload]
        AssignRoleRequest $request,
    ): JsonResponse {
        $role = ($this->assignRole)(Uuid::fromString($id), $request->role, $user->id());

        return new JsonResponse(AssignRoleResponse::from($id, $role), JsonResponse::HTTP_CREATED);
    }
}
