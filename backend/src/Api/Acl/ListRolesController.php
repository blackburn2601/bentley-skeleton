<?php

declare(strict_types=1);

namespace App\Api\Acl;

use App\Acl\Application\Service\ListRolesService;
use App\Api\Acl\Response\ListRolesResponse;
use App\Api\Attribute\RateLimit;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * GET /api/v1/admin/roles.
 *
 * No request DTO: the endpoint takes no parameters, and a DTO with no fields is a file that
 * exists only to be imported. MeController is the precedent.
 */
#[Route('/api/v1/admin/roles', name: 'get_list_roles', methods: ['GET'])]
#[IsGranted('role.read')]
#[RateLimit(policy: 'api_user', keyedBy: 'user')]
final readonly class ListRolesController
{
    public function __construct(private ListRolesService $listRoles)
    {
    }

    public function __invoke(): JsonResponse
    {
        return new JsonResponse(ListRolesResponse::from(($this->listRoles)()));
    }
}
