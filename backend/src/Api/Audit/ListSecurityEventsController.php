<?php

declare(strict_types=1);

namespace App\Api\Audit;

use App\Api\Attribute\RateLimit;
use App\Api\Audit\Request\ListSecurityEventsRequest;
use App\Api\Audit\Response\ListSecurityEventsResponse;
use App\Audit\Application\Service\ListSecurityEventsService;
use App\Shared\Domain\SecurityEventType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * GET /api/v1/admin/audit-events.
 */
#[Route('/api/v1/admin/audit-events', name: 'get_list_security_events', methods: ['GET'])]
#[IsGranted('audit.read')]
#[RateLimit(policy: 'api_user', keyedBy: 'user')]
final readonly class ListSecurityEventsController
{
    public function __construct(private ListSecurityEventsService $listEvents)
    {
    }

    public function __invoke(
        #[MapQueryString]
        ListSecurityEventsRequest $request = new ListSecurityEventsRequest(),
    ): JsonResponse {
        $result = ($this->listEvents)(
            null === $request->type ? [] : [SecurityEventType::from($request->type)],
            $request->q,
            $request->offset(),
            $request->limit(),
        );

        return new JsonResponse(ListSecurityEventsResponse::from(
            $result['items'],
            $request->page,
            $request->perPage,
            $result['total'],
        ));
    }
}
