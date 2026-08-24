<?php

declare(strict_types=1);

namespace App\Api\Account;

use App\Account\Application\Service\EnrolTwoFactorService;
use App\Api\Account\Response\EnrolTwoFactorResponse;
use App\Api\Attribute\RateLimit;
use App\Api\Security\AuthenticatedUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * POST /api/v1/account/mfa/enrol.
 *
 * Self-service: the signed-in caller starts enrolling an authenticator. The response carries the
 * QR data URL, the `otpauth://` provisioning URI and the plaintext secret — all shown once. The
 * secret is held in the provisional slot until a first code confirms it; nothing about the
 * caller's authentication changes yet (ADR-0026).
 */
#[Route('/api/v1/account/mfa/enrol', name: 'post_enrol_two_factor', methods: ['POST'])]
#[IsGranted('account.update')]
#[RateLimit('mfa_enrol', keyedBy: 'user')]
final readonly class EnrolTwoFactorController
{
    public function __construct(private EnrolTwoFactorService $enrolTwoFactor)
    {
    }

    public function __invoke(
        #[CurrentUser]
        AuthenticatedUser $actor,
    ): JsonResponse {
        $enrollment = ($this->enrolTwoFactor)($actor->id());

        return new JsonResponse(EnrolTwoFactorResponse::from($enrollment));
    }
}
