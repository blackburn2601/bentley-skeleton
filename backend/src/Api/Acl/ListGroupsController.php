<?php

declare(strict_types=1);

namespace App\Api\Acl;

use App\Acl\Application\Service\ListGroupsService;
use App\Api\Acl\Response\ListGroupsResponse;
use App\Api\Attribute\RateLimit;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * GET /api/v1/admin/groups.
 */
#[Route('/api/v1/admin/groups', name: 'get_list_groups', methods: ['GET'])]
#[IsGranted('group.read')]
#[RateLimit(policy: 'api_user', keyedBy: 'user')]
final readonly class ListGroupsController
{
    public function __construct(private ListGroupsService $listGroups)
    {
    }

    public function __invoke(): JsonResponse
    {
        return new JsonResponse(ListGroupsResponse::from(($this->listGroups)()));
    }
}
