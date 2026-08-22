<?php

declare(strict_types=1);

namespace App\Api\Account;

use App\Account\Application\Service\SignOutService;
use App\Api\Security\AuthCookies;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * POST /api/v1/auth/logout.
 *
 * Public, and always succeeds. Logging out with an already-invalid token must still clear
 * the cookies — refusing would strand a client that cannot get rid of them.
 */
#[Route('/api/v1/auth/logout', name: 'auth_logout', methods: ['POST'])]
#[IsGranted('PUBLIC_ACCESS')]
final class LogoutController
{
    public function __construct(
        private readonly SignOutService $signOut,
        private readonly AuthCookies $cookies,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $presented = $request->cookies->get(AuthCookies::REFRESH);

        ($this->signOut)(\is_string($presented) ? $presented : null);

        $response = new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);

        foreach ($this->cookies->cleared() as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }
}
