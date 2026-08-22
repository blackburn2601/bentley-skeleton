<?php

declare(strict_types=1);

namespace App\Audit\Infrastructure\Http;

use App\Audit\Application\RequestContext;
use App\Audit\Application\Service\RequestContextProvider;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Reads the current HTTP request, when there is one.
 *
 * Lives in Infrastructure because that is the only layer allowed to know about HttpFoundation
 * (INV-08). Outside a request — a console command, a cron job — it returns an empty context,
 * and the audit row honestly records "no IP" instead of inventing one.
 */
final readonly class HttpRequestContextProvider implements RequestContextProvider
{
    public function __construct(private RequestStack $requests)
    {
    }

    public function current(): RequestContext
    {
        $request = $this->requests->getCurrentRequest();

        if (null === $request) {
            return RequestContext::none();
        }

        $userAgent = $request->headers->get('User-Agent');

        return new RequestContext(
            // getClientIp() honours trusted proxies. If TRUSTED_PROXIES is misconfigured this
            // records the load balancer's address for every user — see docs/OPERATIONS.md,
            // where it is the first thing the rate-limiting runbook tells you to check.
            ipAddress: $request->getClientIp(),
            userAgent: null === $userAgent ? null : mb_substr($userAgent, 0, 255),
            requestId: $this->requestId($request->attributes->get('_request_id')),
        );
    }

    private function requestId(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }
}
