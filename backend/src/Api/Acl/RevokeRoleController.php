<?php

declare(strict_types=1);

namespace App\Api\Acl;

use App\Acl\Application\Service\RevokeRoleService;
use App\Api\Acl\Response\RevokeRoleResponse;
use App\Api\Attribute\RateLimit;
use App\Api\Security\AuthenticatedUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * DELETE /api/v1/admin/users/{id}/roles/{roleName}.
 *
 * The role is named in the path rather than in a body: DELETE bodies are poorly supported by
 * intermediaries, and a role name is already a stable identifier.
 */
#[Route(
    '/api/v1/admin/users/{id}/roles/{roleName}',
    name: 'delete_revoke_role',
    requirements: ['id' => Requirement::UUID, 'roleName' => '[A-Z_]{1,100}'],
    methods: ['DELETE'],
)]
#[IsGranted('permission.revoke')]
#[RateLimit(policy: 'admin_write', keyedBy: 'user')]
final readonly class RevokeRoleController
{
    public function __construct(private RevokeRoleService $revokeRole)
    {
    }

    public function __invoke(
        string $id,
        string $roleName,
        #[CurrentUser]
        AuthenticatedUser $user,
    ): JsonResponse {
        $role = ($this->revokeRole)(Uuid::fromString($id), $roleName, $user->id());

        return new JsonResponse(RevokeRoleResponse::from($id, $role));
    }
}
