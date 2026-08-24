<?php

declare(strict_types=1);

namespace App\Api\Account;

use App\Account\Application\Service\DisableTwoFactorService;
use App\Api\Attribute\RateLimit;
use App\Api\Security\AuthenticatedUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * DELETE /api/v1/account/mfa.
 *
 * Self-service removal of the caller's own second factor. The secret and recovery codes are
 * deleted; the administrator's `mfaRequired` policy is left in place (removing the device is the
 * user's choice, not a relaxation of policy). Every session is revoked, so no `amr: ['totp']`
 * token outlives the disable (ADR-0026).
 */
#[Route('/api/v1/account/mfa', name: 'delete_disable_two_factor', methods: ['DELETE'])]
#[IsGranted('account.update')]
#[RateLimit('mfa_disable', keyedBy: 'user')]
final readonly class DisableTwoFactorController
{
    public function __construct(private DisableTwoFactorService $disableTwoFactor)
    {
    }

    public function __invoke(
        #[CurrentUser]
        AuthenticatedUser $actor,
    ): JsonResponse {
        ($this->disableTwoFactor)($actor->id());

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }
}
