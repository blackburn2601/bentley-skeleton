<?php

declare(strict_types=1);

namespace App\Api\Attribute;

use Attribute;

/**
 * Declares which rate-limit policy guards an endpoint.
 *
 * On the controller, next to `#[Route]` and `#[IsGranted]`, so everything about how an
 * endpoint is protected reads in one place — and so `docs/ENDPOINTS.md` can list it.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RateLimit
{
    /**
     * @param string      $policy       a limiter named in config/packages/rate_limiter.yaml
     * @param string      $keyedBy      'ip', 'user', or 'ip+payload' — see RateLimitSubscriber
     * @param string|null $payloadField request-body field to fold into the key, for 'ip+payload'
     */
    public function __construct(
        public string $policy,
        public string $keyedBy = 'ip',
        public ?string $payloadField = null,
    ) {
    }
}
