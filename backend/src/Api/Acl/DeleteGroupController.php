<?php

declare(strict_types=1);

namespace App\Api\Acl;

use App\Acl\Application\Service\DeleteGroupService;
use App\Api\Acl\Response\DeleteGroupResponse;
use App\Api\Attribute\RateLimit;
use App\Api\Security\AuthenticatedUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * DELETE /api/v1/admin/groups/{id}.
 */
#[Route(
    '/api/v1/admin/groups/{id}',
    name: 'delete_delete_group',
    requirements: ['id' => Requirement::UUID],
    methods: ['DELETE'],
)]
#[IsGranted('group.delete')]
#[RateLimit(policy: 'admin_write', keyedBy: 'user')]
final readonly class DeleteGroupController
{
    public function __construct(private DeleteGroupService $deleteGroup)
    {
    }

    public function __invoke(
        string $id,
        #[CurrentUser]
        AuthenticatedUser $actor,
    ): JsonResponse {
        $result = ($this->deleteGroup)(Uuid::fromString($id), $actor->id());

        return new JsonResponse(DeleteGroupResponse::from($result));
    }
}
