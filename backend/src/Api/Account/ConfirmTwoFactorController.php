<?php

declare(strict_types=1);

namespace App\Api\Account;

use App\Account\Application\Service\ConfirmTwoFactorService;
use App\Api\Account\Request\ConfirmTwoFactorRequest;
use App\Api\Account\Response\ConfirmTwoFactorResponse;
use App\Api\Attribute\RateLimit;
use App\Api\Security\AuthenticatedUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * POST /api/v1/account/mfa/confirm.
 *
 * The caller enters the first code from their new authenticator. On a match the provisional
 * secret goes live and the response carries the single-use recovery codes, shown once. The
 * caller's other sessions end here — they authenticated before this factor existed — so the
 * caller re-proves the new factor on the next login (ADR-0026).
 */
#[Route('/api/v1/account/mfa/confirm', name: 'post_confirm_two_factor', methods: ['POST'])]
#[IsGranted('account.update')]
#[RateLimit('mfa_confirm', keyedBy: 'user')]
final readonly class ConfirmTwoFactorController
{
    public function __construct(private ConfirmTwoFactorService $confirmTwoFactor)
    {
    }

    public function __invoke(
        #[CurrentUser]
        AuthenticatedUser $actor,
        #[MapRequestPayload]
        ConfirmTwoFactorRequest $payload,
    ): JsonResponse {
        $recoveryCodes = ($this->confirmTwoFactor)($actor->id(), $payload->code);

        return new JsonResponse(ConfirmTwoFactorResponse::from($recoveryCodes));
    }
}
