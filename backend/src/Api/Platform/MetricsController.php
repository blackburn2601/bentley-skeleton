<?php

declare(strict_types=1);

namespace App\Api\Platform;

use App\Platform\Application\Service\CollectMetricsService;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * GET /metrics — Prometheus scrape endpoint.
 *
 * Restricted by IP rather than by permission, because a scraper has no credentials and giving
 * it any would mean a long-lived token in a monitoring config. The metrics are not secret,
 * but they are useful reconnaissance: account counts and lockout rates tell an attacker how
 * large the system is and whether their guessing is being noticed.
 */
#[Route('/metrics', name: 'metrics', methods: ['GET'])]
#[IsGranted('PUBLIC_ACCESS')]
final readonly class MetricsController
{
    public function __construct(
        private CollectMetricsService $collect,
        private string $allowedCidrs,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (!$this->isAllowed($request)) {
            // 404, not 403: an unauthorised scraper learns nothing about whether this endpoint
            // exists, which is the point of restricting it in the first place.
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        return new Response(
            ($this->collect)(),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
                'Cache-Control' => 'no-store',
            ],
        );
    }

    private function isAllowed(Request $request): bool
    {
        $cidrs = array_values(array_filter(
            array_map(trim(...), explode(',', $this->allowedCidrs)),
            static fn (string $cidr): bool => '' !== $cidr,
        ));

        if ([] === $cidrs) {
            return false;
        }

        return IpUtils::checkIp((string) $request->getClientIp(), $cidrs);
    }
}
