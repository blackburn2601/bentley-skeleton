<?php

declare(strict_types=1);

namespace App\Api\Acl;

use App\Acl\Application\Service\ListGroupMembersService;
use App\Api\Acl\Response\ListGroupMembersResponse;
use App\Api\Attribute\RateLimit;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * GET /api/v1/admin/groups/{id}/members.
 */
#[Route(
    '/api/v1/admin/groups/{id}/members',
    name: 'get_list_group_members',
    requirements: ['id' => Requirement::UUID],
    methods: ['GET'],
)]
#[IsGranted('group.read')]
#[RateLimit(policy: 'api_user', keyedBy: 'user')]
final readonly class ListGroupMembersController
{
    public function __construct(private ListGroupMembersService $listMembers)
    {
    }

    public function __invoke(string $id): JsonResponse
    {
        return new JsonResponse(ListGroupMembersResponse::from(($this->listMembers)(Uuid::fromString($id))));
    }
}
