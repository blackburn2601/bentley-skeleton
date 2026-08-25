# 0030. Fixed-window auth lockout limiters report an accurate Retry-After

- **Status:** accepted
- **Date:** 2026-08-25
- **Deciders:** Sebastian Wagner

## Context

The three credential-guessing limiters — `login`, `totp_verify`, `recovery_verify` — were all
`sliding_window`, 5 attempts per 15 minutes (ADR-0008, ADR-0026). The `RateLimitSubscriber`
sets two countdown values on a 429: the `Retry-After` header and the
`"Too many requests. Try again in N seconds."` detail, both derived from
`RateLimit::getRetryAfter()`.

For `sliding_window`, `getRetryAfter()` is **not** the real window reset. It comes from
`SlidingWindow::calculateTimeForTokens()`, a proportional drip-rate estimate
(`needed * remainingWindow / releasable`). For a burst that fills a 5 / 15 min window, that
formula evaluates to roughly `1 * 899 / 5 ≈ 180 s`. But Symfony's sliding window only releases
capacity at window boundaries (`windowEndAt = firstHit + interval` = +900 s) via
previous-window weighting, so the **real** wait is ~900 s (15 min). The headers said 180 s
(3 min); a caller who waited 180 s and retried was still blocked.

Two compounding effects made it read as "the limiter is broken":

- `X-RateLimit-Reset` on **accepted** requests with remaining budget is `≈ now` (Symfony sets
  `retryAfter = $now`), so the "countdown" sits at ~0 while budget remains — meaningless as a
  running indicator.
- Rejected requests still call `$window->add($tokens)` in `SlidingWindowLimiter::reserve()`,
  so a retry encouraged by the wrong 180 s **increments** the hit count and pushes the real
  release past the next window flip (900 s → ~1050 s → ~1157 s …). The shorter the advertised
  wait, the more the real wait grows.

The user-facing symptom — "nach MFA-Setup mit Ausloggen und Einloggen direkt im Limit, und
die Sekundenzahl passt nicht zu den wirklich gewarteten Sekunden" — was the `login` window
accumulating editor attempts during MFA debugging (correct limiter behaviour) combined with
the lying 180 s countdown (the actual bug).

The wrong value originates in Symfony's `SlidingWindowLimiter`, not in the subscriber: the
subscriber passes `getRetryAfter()` through uncritically. No test covered it — `.env.test`
sets `RATE_LIMIT_ENABLED=0` so the auth-flow tests can repeat logins without tripping 429, so
no functional test ever saw a 429.

## Decision

Switch the three credential-guessing limiters — `login`, `totp_verify`, `recovery_verify` —
from `sliding_window` to `fixed_window`. Limit and interval are unchanged (5 / 15 min).

For `fixed_window`, `Window::calculateTimeForTokens()` returns the real time until the bucket
resets (`ceil(timer + interval - now)`), so `Retry-After` and the "Try again in N seconds"
detail become the honest ~900 s for a burst-exhausted window. The fixed bucket also resets
cleanly at a known time: an inflated hit count from premature retries does **not** carry
forward into the next window the way sliding's previous-window weighting does, which defuses
the compounding block.

The boundary-burst property of a fixed window — up to ~2× the limit in the second a window
flips — is accepted for these three limiters. They are the **first** throttle on
credential-guessing, not the only defence: account lockout, MFA, and audit events
(`MfaChallengeFailed`, `login_failed`) all defend the same paths, so a few extra attempts at a
window edge do not change the security posture. Accurate lockout UX is worth more here than
the smoothness a sliding window buys.

The other `sliding_window` policies (`change_password`, `refresh`, `api_anon`,
`admin_write`, `mfa_enrol`, `mfa_confirm`, `mfa_disable`, `mfa_admin_set`,
`mfa_admin_reset`) are deliberately left. Most are throughput-shaped rather than lockout-UX
(`api_anon`, `admin_write`, `refresh`), or authenticated self-service where hitting the limit
is rare and the countdown is not the user's primary signal (`change_password`, the `mfa_*`
self-service). They share the same drip-estimate inaccuracy on a 429, but it is not the
user-facing "try again in N seconds" lockout path that prompted this ADR; revisiting them is a
separate decision. `api_user` stays `token_bucket` (ADR-0008): real clients are bursty and a
window would reject a page load while allowing a steady drip.

A functional test pins the fix: `LoginRateLimitTest` exhausts the configured `login` limiter
through the `limiter.login` factory and asserts the rejected `RateLimit`'s retry-after is
~900 s, with an 800 s floor that the old ~180 s sliding estimate falls well below. It goes
red if the policy reverts to `sliding_window`.

## Consequences

### Positive

- The lockout countdown tells the truth: `Retry-After` and "Try again in N seconds" reflect
  the real seconds until the bucket resets, so a caller who waits the advertised time can
  actually retry.
- The compounding block is gone: premature retries no longer extend the real wait past the
  window flip, because a fixed bucket resets at a known time regardless of hit-count
  inflation.
- The regression test documents the exact old wrong value (180 s) and guards the policy
  choice directly.

### Negative

- A boundary burst is possible: up to ~2× the limit (~10 instead of 5 login attempts) in the
  second a window flips. Acceptable for these paths (see Decision), but it is a real
  loosening of the smoothness a sliding window provided.
- The same drip-estimate inaccuracy still affects the deliberately-left `sliding_window`
  policies. This ADR does not claim to have fixed rate limiting globally; it fixes the
  user-facing lockout countdown that was reported.
- `X-RateLimit-Reset` on accepted requests with remaining budget is still `≈ now` (Symfony's
  behaviour, unchanged). That is standard for the header and not part of this fix; the
  user-facing countdown is the 429's `Retry-After`, which is now accurate.

## Alternatives rejected and why

- **Compute `Retry-After` from the real `windowEndAt` in the subscriber, keep
  `sliding_window`.** Rejected: it would reach into `SlidingWindow` internals (the window end
  is not exposed on `RateLimit`), require a custom limiter wrapper or storage peek, and break
  across Symfony upgrades. A policy swap in one YAML file achieves the same honest countdown
  for far less machinery — the repo values not building interfaces/wrappers without a second
  implementer or real port boundary.

- **Stop rejected requests from incrementing (`$window->add` on rejection), keep
  `sliding_window`.** Rejected as the sole fix: it defuses the compounding block but leaves
  the wrong 180 s number — the exact thing the user saw. It is a complementary hardening, not
  a substitute for an accurate countdown.

- **Switch every `sliding_window` policy to `fixed_window`.** Rejected for scope: the
  reported bug is the auth lockout UX. The throughput-shaped policies (`api_anon`,
  `admin_write`) and authenticated self-service (`change_password`, `mfa_*`) have different
  risk profiles where sliding smoothness is the better trade-off, and changing them all at
  once would mix one decision with several unanalysed ones. They can be revisited per policy.

## Reversal cost

Cheap. Reversing is changing three `policy: 'fixed_window'` lines back to `'sliding_window'`
in `config/packages/rate_limiter.yaml` (and the `when@test` `login` line). The regression
test then fails — which is the intended signal that the lying countdown is back. No schema
change, no data migration, no API contract change; the rate-limit counters in Redis are
ephemeral and reset on their own.

## Implemented by

- `backend/config/packages/rate_limiter.yaml` — `login`, `totp_verify`, `recovery_verify`
  switched to `fixed_window` (limit and interval unchanged); `when@test` `login` updated to
  match.
- `backend/tests/Functional/Account/LoginRateLimitTest.php` — exhausts the configured `login`
  limiter and asserts the rejected retry-after is ~900 s, not the ~180 s sliding estimate.