<?php

declare(strict_types=1);

namespace App\Api\Acl;

use App\Acl\Application\Service\DeleteRoleService;
use App\Api\Acl\Response\DeleteRoleResponse;
use App\Api\Attribute\RateLimit;
use App\Api\Security\AuthenticatedUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * DELETE /api/v1/admin/roles/{id}.
 */
#[Route(
    '/api/v1/admin/roles/{id}',
    name: 'delete_delete_role',
    requirements: ['id' => Requirement::UUID],
    methods: ['DELETE'],
)]
#[IsGranted('role.delete')]
#[RateLimit(policy: 'admin_write', keyedBy: 'user')]
final readonly class DeleteRoleController
{
    public function __construct(private DeleteRoleService $deleteRole)
    {
    }

    public function __invoke(
        string $id,
        #[CurrentUser]
        AuthenticatedUser $actor,
    ): JsonResponse {
        $result = ($this->deleteRole)(Uuid::fromString($id), $actor->id());

        return new JsonResponse(DeleteRoleResponse::from($result));
    }
}
