<?php

declare(strict_types=1);

namespace App\Api\Acl;

use App\Acl\Application\Service\SetGroupRolesService;
use App\Api\Acl\Request\SetGroupRolesRequest;
use App\Api\Acl\Response\SetGroupRolesResponse;
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
 * PUT /api/v1/admin/groups/{id}/roles.
 */
#[Route(
    '/api/v1/admin/groups/{id}/roles',
    name: 'put_set_group_roles',
    requirements: ['id' => Requirement::UUID],
    methods: ['PUT'],
)]
#[IsGranted('group.update')]
#[RateLimit(policy: 'admin_write', keyedBy: 'user')]
final readonly class SetGroupRolesController
{
    public function __construct(private SetGroupRolesService $setGroupRoles)
    {
    }

    public function __invoke(
        string $id,
        #[CurrentUser]
        AuthenticatedUser $actor,
        #[MapRequestPayload]
        SetGroupRolesRequest $request,
    ): JsonResponse {
        $result = ($this->setGroupRoles)(Uuid::fromString($id), array_values($request->roles), $actor->id());

        return new JsonResponse(SetGroupRolesResponse::from($result));
    }
}
