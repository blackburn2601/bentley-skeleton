<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Domain\Clock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The real clock. Always UTC — a server whose timezone changes must not change token expiry.
 */
final readonly class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
