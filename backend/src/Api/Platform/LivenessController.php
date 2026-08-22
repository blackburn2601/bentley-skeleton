<?php

declare(strict_types=1);

namespace App\Api\Platform;

use App\Api\Attribute\NoServiceDelegation;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * GET /health/live — is this process alive?
 *
 * Deliberately checks nothing. Liveness and readiness answer different questions, and
 * conflating them is a classic outage: if liveness probed the database, a brief database
 * blip would make the orchestrator conclude every container was broken and restart the
 * entire fleet — turning a recoverable dependency failure into a full outage.
 *
 * Liveness means "this process is not wedged; do not restart me". Readiness means "I can
 * serve requests right now". Only readiness looks at dependencies.
 *
 * This is the one endpoint that legitimately has no service call: there is nothing to ask.
 */
#[Route('/health/live', name: 'health_live', methods: ['GET'])]
#[IsGranted('PUBLIC_ACCESS')]
#[NoServiceDelegation(reason: 'Liveness deliberately checks nothing; there is no work to delegate.')]
final class LivenessController
{
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['status' => 'alive']);
    }
}
