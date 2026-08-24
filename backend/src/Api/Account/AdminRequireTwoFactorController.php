<?php

declare(strict_types=1);

namespace App\Api\Account;

use App\Account\Application\Service\AdminRequireTwoFactorService;
use App\Api\Account\Request\AdminRequireTwoFactorRequest;
use App\Api\Account\Response\AdminRequireTwoFactorResponse;
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
 * PUT /api/v1/admin/users/{id}/mfa/required.
 *
 * An administrator enforces or lifts the MFA requirement on one user. The requirement takes
 * effect at the next login; a user required but not yet enrolled is refused with
 * `mfa_required_not_enrolled` rather than force-enrolled at the prompt (ADR-0026).
 */
#[Route(
    '/api/v1/admin/users/{id}/mfa/required',
    name: 'put_admin_require_two_factor',
    requirements: ['id' => Requirement::UUID],
    methods: ['PUT'],
)]
#[IsGranted('user.update')]
#[RateLimit('mfa_admin_set', keyedBy: 'user')]
final readonly class AdminRequireTwoFactorController
{
    public function __construct(private AdminRequireTwoFactorService $adminRequireTwoFactor)
    {
    }

    public function __invoke(
        string $id,
        #[CurrentUser]
        AuthenticatedUser $actor,
        #[MapRequestPayload]
        AdminRequireTwoFactorRequest $request,
    ): JsonResponse {
        $user = ($this->adminRequireTwoFactor)(Uuid::fromString($id), $request->required, $actor->id());

        return new JsonResponse(AdminRequireTwoFactorResponse::from($user));
    }
}
