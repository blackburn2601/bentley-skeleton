<?php

declare(strict_types=1);

namespace App\Api\Audit;

use App\Api\Attribute\RateLimit;
use App\Api\Audit\Response\EraseUserResponse;
use App\Api\Security\AuthenticatedUser;
use App\Audit\Application\Service\EraseUserService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * DELETE /api/v1/admin/users/{id}.
 *
 * Anonymises rather than deletes: rows elsewhere reference this id, and the audit trail must
 * survive the erasure it records (ADR-0012).
 */
#[Route(
    '/api/v1/admin/users/{id}',
    name: 'delete_erase_user',
    requirements: ['id' => Requirement::UUID],
    methods: ['DELETE'],
)]
#[IsGranted('user.delete')]
#[RateLimit(policy: 'admin_write', keyedBy: 'user')]
final readonly class EraseUserController
{
    public function __construct(private EraseUserService $eraseUser)
    {
    }

    public function __invoke(string $id, #[CurrentUser] AuthenticatedUser $actor): JsonResponse
    {
        $result = ($this->eraseUser)(Uuid::fromString($id), $actor->id());

        return new JsonResponse(EraseUserResponse::from($result['erased'], $result['sessionsRevoked']));
    }
}
