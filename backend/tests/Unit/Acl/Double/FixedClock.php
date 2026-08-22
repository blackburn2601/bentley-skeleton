<?php

declare(strict_types=1);

namespace App\Tests\Unit\Acl\Double;

use App\Shared\Domain\Clock;
use DateTimeImmutable;

/**
 * A clock that does not move, so expiry tests are three lines instead of a sleep.
 */
final class FixedClock implements Clock
{
    public function __construct(private DateTimeImmutable $now = new DateTimeImmutable('2026-01-01T12:00:00+00:00'))
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function travelTo(string $moment): void
    {
        $this->now = new DateTimeImmutable($moment);
    }
}
