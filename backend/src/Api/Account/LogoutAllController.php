<?php

declare(strict_types=1);

namespace App\Api\Account;

use App\Account\Application\Service\RevokeAllSessionsService;
use App\Api\Security\AuthCookies;
use App\Api\Security\AuthenticatedUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * POST /api/v1/auth/logout-all.
 *
 * "Sign out everywhere" — the button someone presses when they think they have been
 * compromised. Requires an authenticated caller, unlike plain logout.
 */
#[Route('/api/v1/auth/logout-all', name: 'auth_logout_all', methods: ['POST'])]
#[IsGranted('account.update')]
final readonly class LogoutAllController
{
    public function __construct(
        private RevokeAllSessionsService $revokeAll,
        private AuthCookies $cookies,
    ) {
    }

    public function __invoke(#[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $revoked = ($this->revokeAll)($user->id());

        $response = new JsonResponse(['sessionsRevoked' => $revoked]);

        foreach ($this->cookies->cleared() as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }
}
