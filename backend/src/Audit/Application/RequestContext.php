<?php

declare(strict_types=1);

namespace App\Audit\Application;

/**
 * Where a request came from, for the audit trail.
 *
 * A value object rather than reading the Request inside services, because Application must
 * not know about HTTP (INV-08). An HTTP listener fills this in; a console command leaves it
 * empty, and the audit row is honest about that rather than inventing a fake IP.
 */
final readonly class RequestContext
{
    public function __construct(
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public ?string $requestId = null,
    ) {
    }

    public static function none(): self
    {
        return new self();
    }
}
