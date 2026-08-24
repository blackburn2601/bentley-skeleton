<?php

declare(strict_types=1);

namespace App\Api\Account;

use App\Account\Application\Service\ChangePasswordService;
use App\Api\Account\Request\ChangePasswordRequest;
use App\Api\Attribute\RateLimit;
use App\Api\Security\AuthenticatedUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * POST /api/v1/auth/change-password.
 *
 * Self-service: the signed-in caller rotates their own password after proving they still know
 * the current one. The current session is left intact — the caller is using it — and other
 * sessions are not touched (ADR-0024).
 */
#[Route('/api/v1/auth/change-password', name: 'post_change_password', methods: ['POST'])]
#[IsGranted('account.update')]
#[RateLimit(policy: 'change_password', keyedBy: 'user')]
final readonly class ChangePasswordController
{
    public function __construct(private ChangePasswordService $changePassword)
    {
    }

    public function __invoke(
        #[CurrentUser]
        AuthenticatedUser $actor,
        #[MapRequestPayload]
        ChangePasswordRequest $request,
    ): JsonResponse {
        ($this->changePassword)($actor->id(), $request->currentPassword, $request->newPassword);

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }
}
