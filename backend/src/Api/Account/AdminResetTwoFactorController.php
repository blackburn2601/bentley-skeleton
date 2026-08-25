<?php

declare(strict_types=1);

namespace App\Api\Account;

use App\Account\Application\Service\AdminResetTwoFactorService;
use App\Api\Attribute\RateLimit;
use App\Api\Security\AuthenticatedUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * POST /api/v1/admin/users/{id}/mfa/reset.
 *
 * An administrator strips a user's second factor outright: secret, recovery codes, and the
 * requirement all go. The user is back on the no-MFA floor and the admin can re-require it.
 * Every session is revoked and the action is audited at high severity (ADR-0026).
 */
#[Route(
    '/api/v1/admin/users/{id}/mfa/reset',
    name: 'post_admin_reset_two_factor',
    requirements: ['id' => Requirement::UUID],
    methods: ['POST'],
)]
#[IsGranted('user.update')]
#[RateLimit('mfa_admin_reset', keyedBy: 'user')]
final readonly class AdminResetTwoFactorController
{
    public function __construct(private AdminResetTwoFactorService $adminResetTwoFactor)
    {
    }

    public function __invoke(
        string $id,
        #[CurrentUser]
        AuthenticatedUser $actor,
    ): JsonResponse {
        ($this->adminResetTwoFactor)(Uuid::fromString($id), $actor->id());

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }
}
