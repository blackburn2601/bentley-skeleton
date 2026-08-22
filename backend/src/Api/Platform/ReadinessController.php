<?php

declare(strict_types=1);

namespace App\Api\Platform;

use App\Api\Platform\Response\ReadinessResponse;
use App\Platform\Application\Service\CheckReadinessService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * GET /health/ready — should this replica receive traffic?
 *
 * Unauthenticated on purpose: an orchestrator probing this endpoint has no credentials, and
 * making it authenticated is how deployments end up with no health checking at all. The
 * response therefore carries probe *names* and exception class names only — never a message,
 * a DSN or a hostname.
 */
#[Route('/health/ready', name: 'health_ready', methods: ['GET'])]
#[IsGranted('PUBLIC_ACCESS')]
final readonly class ReadinessController
{
    public function __construct(
        private CheckReadinessService $checkReadiness,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $result = ($this->checkReadiness)();

        return new JsonResponse(
            ReadinessResponse::from($result),
            $result['ready'] ? JsonResponse::HTTP_OK : JsonResponse::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
