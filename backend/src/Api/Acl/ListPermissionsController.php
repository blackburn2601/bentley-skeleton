<?php

declare(strict_types=1);

namespace App\Api\Acl;

use App\Acl\Application\Service\ListPermissionsService;
use App\Api\Acl\Response\ListPermissionsResponse;
use App\Api\Attribute\RateLimit;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * GET /api/v1/admin/permissions.
 */
#[Route('/api/v1/admin/permissions', name: 'get_list_permissions', methods: ['GET'])]
#[IsGranted('permission.read')]
#[RateLimit(policy: 'api_user', keyedBy: 'user')]
final readonly class ListPermissionsController
{
    public function __construct(private ListPermissionsService $listPermissions)
    {
    }

    public function __invoke(): JsonResponse
    {
        return new JsonResponse(ListPermissionsResponse::from(($this->listPermissions)()));
    }
}
