<?php

declare(strict_types=1);

namespace App\Api\Audit;

use App\Api\Security\AuthCookies;
use App\Api\Security\AuthenticatedUser;
use App\Audit\Application\Service\ErasePersonalDataService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * DELETE /api/v1/me — GDPR Article 17.
 *
 * Anonymises rather than deletes: the security event log must survive the account it
 * describes, or an audit trail can be erased by its own subject. See ErasePersonalDataService
 * for the Article 17(3) reasoning and docs/SECURITY.md for the retention exception.
 */
#[Route('/api/v1/me', name: 'me_erase', methods: ['DELETE'])]
#[IsGranted('account.delete')]
final readonly class EraseMyAccountController
{
    public function __construct(
        private ErasePersonalDataService $erase,
        private AuthCookies $cookies,
    ) {
    }

    public function __invoke(#[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $result = ($this->erase)($user->id());

        $response = new JsonResponse([
            'erased' => $result['erased'],
            'sessionsRevoked' => $result['sessionsRevoked'],
            'message' => 'Your account has been anonymised. Security records are retained as '
                .'required, and no longer identify you.',
        ]);

        foreach ($this->cookies->cleared() as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }
}
