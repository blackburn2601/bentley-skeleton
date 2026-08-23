<?php

declare(strict_types=1);

namespace App\Api\Account;

use App\Account\Application\Service\ListUsersService;
use App\Account\Domain\UserStatus;
use App\Acl\Domain\PermissionCatalog;
use App\Api\Account\Request\ListUsersRequest;
use App\Api\Account\Response\ListUsersResponse;
use App\Api\Attribute\RateLimit;
use App\Api\Security\AuthenticatedUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * GET /api/v1/admin/users.
 *
 * A DTO in, one permission check, one service call, one response view out.
 * Anything more than that belongs in ListUsersService.
 *
 * The caller's id is passed down because the ACL filters the query by it: `user.read` here
 * means other people's records, and which ones depends on who is asking.
 */
#[Route('/api/v1/admin/users', name: 'get_list_users', methods: ['GET'])]
#[IsGranted('user.read')]
#[RateLimit(policy: 'api_user', keyedBy: 'user')]
final readonly class ListUsersController
{
    public function __construct(
        private ListUsersService $listUsers,
    ) {
    }

    public function __invoke(
        #[CurrentUser]
        AuthenticatedUser $user,
        #[MapQueryString]
        ListUsersRequest $request = new ListUsersRequest(),
    ): JsonResponse {
        $result = ($this->listUsers)(
            $user->id(),
            PermissionCatalog::USER_READ,
            $request->offset(),
            $request->limit(),
            $request->q,
            null === $request->status ? null : UserStatus::from($request->status),
        );

        return new JsonResponse(ListUsersResponse::from(
            $result['items'],
            $request->page,
            $request->perPage,
            $result['total'],
        ));
    }
}
