<?php

declare(strict_types=1);

namespace App\Api\Audit;

use App\Api\Security\AuthenticatedUser;
use App\Audit\Application\Service\ExportPersonalDataService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * POST /api/v1/me/export — GDPR Article 15.
 *
 * POST, not GET: it writes an audit event, and a GET that mutates state gets fired by link
 * prefetchers and security scanners.
 */
#[Route('/api/v1/me/export', name: 'me_export', methods: ['POST'])]
#[IsGranted('account.export')]
final readonly class ExportMyDataController
{
    public function __construct(private ExportPersonalDataService $export)
    {
    }

    public function __invoke(#[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        $response = new JsonResponse(($this->export)($user->id()));

        // This body is the caller's personal data. It must not sit in a shared cache, and it
        // should not be written to disk by a well-meaning proxy.
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Content-Disposition', 'attachment; filename="personal-data-export.json"');

        return $response;
    }
}
