<?php

declare(strict_types=1);

namespace App\Api\Acl;

use App\Acl\Application\Service\SetGroupMembersService;
use App\Api\Acl\Request\SetGroupMembersRequest;
use App\Api\Acl\Response\SetGroupMembersResponse;
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
 * PUT /api/v1/admin/groups/{id}/members.
 */
#[Route(
    '/api/v1/admin/groups/{id}/members',
    name: 'put_set_group_members',
    requirements: ['id' => Requirement::UUID],
    methods: ['PUT'],
)]
#[IsGranted('group.update')]
#[RateLimit(policy: 'admin_write', keyedBy: 'user')]
final readonly class SetGroupMembersController
{
    public function __construct(private SetGroupMembersService $setGroupMembers)
    {
    }

    public function __invoke(
        string $id,
        #[CurrentUser]
        AuthenticatedUser $actor,
        #[MapRequestPayload]
        SetGroupMembersRequest $request,
    ): JsonResponse {
        $result = ($this->setGroupMembers)(Uuid::fromString($id), array_values($request->members), $actor->id());

        return new JsonResponse(SetGroupMembersResponse::from($result));
    }
}
