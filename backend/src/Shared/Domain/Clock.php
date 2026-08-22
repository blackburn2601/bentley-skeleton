<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use DateTimeImmutable;

/**
 * The current time, as a dependency.
 *
 * Token expiry, lockout windows and ACE expiry are all "is it after X?" decisions. Calling
 * `new DateTimeImmutable()` inside those makes them untestable without sleeping, so every
 * such test either sleeps (slow, flaky) or is not written. Injecting the clock means an
 * expiry test is three lines and deterministic.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
