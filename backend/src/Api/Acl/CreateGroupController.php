<?php

declare(strict_types=1);

namespace App\Api\Acl;

use App\Acl\Application\Service\CreateGroupService;
use App\Api\Acl\Request\CreateGroupRequest;
use App\Api\Acl\Response\CreateGroupResponse;
use App\Api\Attribute\RateLimit;
use App\Api\Security\AuthenticatedUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * POST /api/v1/admin/groups.
 */
#[Route(
    '/api/v1/admin/groups',
    name: 'post_create_group',
    methods: ['POST'],
)]
#[IsGranted('group.create')]
#[RateLimit(policy: 'admin_write', keyedBy: 'user')]
final readonly class CreateGroupController
{
    public function __construct(private CreateGroupService $createGroup)
    {
    }

    public function __invoke(
        #[CurrentUser]
        AuthenticatedUser $actor,
        #[MapRequestPayload]
        CreateGroupRequest $request,
    ): JsonResponse {
        $result = ($this->createGroup)($request->name, $request->description);

        return new JsonResponse(CreateGroupResponse::from($result), JsonResponse::HTTP_CREATED);
    }
}
