<?php

declare(strict_types=1);

namespace App\Api\Acl;

use App\Acl\Application\Service\UpdateGroupService;
use App\Api\Acl\Request\UpdateGroupRequest;
use App\Api\Acl\Response\UpdateGroupResponse;
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
 * PATCH /api/v1/admin/groups/{id}.
 */
#[Route(
    '/api/v1/admin/groups/{id}',
    name: 'patch_update_group',
    requirements: ['id' => Requirement::UUID],
    methods: ['PATCH'],
)]
#[IsGranted('group.update')]
#[RateLimit(policy: 'admin_write', keyedBy: 'user')]
final readonly class UpdateGroupController
{
    public function __construct(private UpdateGroupService $updateGroup)
    {
    }

    public function __invoke(
        string $id,
        #[CurrentUser]
        AuthenticatedUser $actor,
        #[MapRequestPayload]
        UpdateGroupRequest $request,
    ): JsonResponse {
        $result = ($this->updateGroup)(Uuid::fromString($id), $request->name, $request->description);

        return new JsonResponse(UpdateGroupResponse::from($result));
    }
}
