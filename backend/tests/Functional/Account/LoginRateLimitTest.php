<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The login limiter reports the real window reset, not a drip-rate estimate (ADR-0030).
 *
 * `login` is a fixed window of 5 / 15 min. When a burst fills it, the retry-after must be
 * the best part of 900 s — the seconds until the bucket resets. Under the previous
 * sliding_window policy the same burst reported ~180 s (Symfony's proportional drip
 * estimate, `needed * remainingWindow / releasable`), so a caller who waited the advertised
 * time was still blocked. That is the bug that reads as "the limiter is broken" when only the
 * number is wrong.
 *
 * The limiter is disabled in `.env.test` (`RATE_LIMIT_ENABLED=0`) so the auth-flow tests can
 * repeat logins without tripping 429, so this goes at the limiter directly through the
 * configured `limiter.login` factory rather than through the HTTP subscriber. What the
 * subscriber emits is `RateLimit::getRetryAfter()` verbatim, so the factory's number IS the
 * number on the wire. This test goes red if the policy is switched back to sliding_window.
 */
final class LoginRateLimitTest extends KernelTestCase
{
    public function testABurstExhaustingTheWindowReportsTheRealResetNotADripEstimate(): void
    {
        self::bootKernel();

        $factory = self::getContainer()->get('limiter.login');
        \assert($factory instanceof RateLimiterFactoryInterface);

        // A bucket keyed on a value no other test uses, so it cannot inherit budget.
        $limiter = $factory->create('login-rate-limit-test');

        // Five accepted hits fill the 5-per-15-min window. The limiter consumes on the
        // controller event before auth runs, so the budget is spent on bad credentials too;
        // the exact identity of the caller does not matter here, only that the bucket fills.
        for ($i = 0; 5 > $i; ++$i) {
            self::assertTrue(
                $limiter->consume(1)->isAccepted(),
                "Hit $i must be accepted while the window still has budget.",
            );
        }

        // The sixth is refused. `consume()` catches its own MaxWaitDurationExceededException
        // and returns the rejected RateLimit, so this is a value, not a throw.
        $rejected = $limiter->consume(1);
        self::assertFalse($rejected->isAccepted(), 'The sixth hit must be refused once the window is full.');

        $retryAfter = $rejected->getRetryAfter()->getTimestamp() - time();

        // Fixed window: the bucket was filled within ~1 s, so the real reset is the best part
        // of the 900 s interval. The old sliding_window drip estimate reported ~180 here; the
        // 800 s floor is what makes this a regression test for ADR-0030 rather than a loose
        // range check — 180 is well below it, 900 is well above it.
        self::assertGreaterThan(
            800,
            $retryAfter,
            'Retry-After must reflect the real ~900 s window reset, not the old ~180 s drip estimate.',
        );
        self::assertLessThanOrEqual(
            900,
            $retryAfter,
            'Retry-After cannot exceed the 15-minute interval.',
        );

        self::assertSame(0, $rejected->getRemainingTokens(), 'A full window leaves no remaining tokens.');
        self::assertSame(5, $rejected->getLimit());
    }
}
