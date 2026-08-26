# 0031. Refresh cookie drops __Host- prefix to allow a scoped path

- **Status:** accepted
- **Date:** 2026-08-26
- **Deciders:** Sebastian Wagner

## Context

A user signed in, was idle for ~40 minutes, and was forced to re-authenticate on the next
click — far short of the documented 30-day sliding session. Investigation showed the
refresh-token flow had **never** worked:

- The audit log held **zero** `refresh_token_rotated` events, ever.
- The refresh token row from the last login was `used = false`, `revoked = false`, and
  `expires_at` well in the future — it was never presented to the server.
- Login and the first ~10 minutes of use worked fine (the access cookie is `Path=/` and valid).

The root cause is in `AuthCookies::REFRESH`, which was `'__Host-bentley_rt'` set with
`Path=/api/v1/auth`. RFC 6265bis requires a `__Host-`-prefixed cookie to be `Secure`, to have
**no** `Domain`, and — critically — to use `Path=/`. A `__Host-` cookie with any other path is
**silently discarded** by the browser (and by curl). So the refresh cookie was never stored,
`/api/v1/auth/refresh` arrived without it, `RotateRefreshTokenService` found no token, and
every session ended at the first 10-minute idle (the access-token TTL) instead of after 30
days.

ADR-0002 contains the contradiction itself: it states both "both tokens live in `__Host-`
prefixed cookies with scoped paths" and "`__Host-` forbids a Domain attribute and requires
`Path=/`". The two are incompatible; the second is the spec, the first was the bug.

CI never caught it because Symfony's `KernelBrowser` uses BrowserKit's cookie jar, which does
**not** enforce the `__Host-` `Path=/` rule — it stores the cookie regardless. So the
refresh-rotation functional test passed against a test double while a real browser dropped
the cookie. The test asserted the raw `Set-Cookie` header for `secure`/`httponly`/`samesite`
and the scoped `path`, but never asserted the `__Host-`-vs-`Path` compatibility that actually
matters.

This is a silently-active bug since the cookie layer was written: refresh never worked, and
the effective session was the 10-minute access TTL, not the 30-day refresh TTL. It is not
caused by the rate-limiter change (ADR-0030, which does not touch the refresh path), by the
token TTLs, or by the dev stack being recreated.

## Decision

Drop the `__Host-` prefix from the refresh cookie only: `AuthCookies::REFRESH` becomes
`'bentley_rt'`. Everything else about the cookie is unchanged — `Secure`, `HttpOnly`,
`SameSite=Strict`, no `Domain` (host-only by construction), and `Path=/api/v1/auth` (the scoped
path that keeps the long-lived credential off every ordinary request).

`__Host-` and a scoped path are mutually exclusive, and the scoped path is the property worth
keeping here: it means the refresh token is sent only to `/api/v1/auth/*`, not attached to
every API call. The access and CSRF cookies keep `__Host-` (both are `Path=/`, where the
prefix is valid and earns its subdomain-fixation protection). The refresh cookie keeps the
*other* `__Host-` guarantees — `Secure`, host-only, `SameSite=Strict` — by construction in
`AuthCookies::build()`, just not the prefix that would force `Path=/`.

A regression guard is added to `AuthenticationFlowTest`: a scoped-path cookie must not start
with `__Host-`, and the access cookie (which is `Path=/`) must. This is the invariant whose
absence let the bug through; it goes red if `__Host-` is ever re-added to the refresh cookie.

The `app.cookie_prefix: 'bentley'` parameter in `services.yaml` is unused — cookie names are
hardcoded constants, consistent with how the access and CSRF cookies already work. This
skeleton is renamed by `bin/new-project`, which does a global word-boundary substitution
`\bbentley\b → <short>` across all tracked files, so `bentley_rt` becomes `<short>_rt` for a
generated project, exactly as `__Host-bentley_at` becomes `__Host-<short>_at`. Deriving the
names from the parameter instead is a principled follow-up, deliberately not bundled with
this bug fix.

## Consequences

### Positive

- Refresh actually works: the cookie is stored by real browsers, `/auth/refresh` receives
  it, and a session survives idle up to the 30-day sliding TTL — as ADR-0002 always intended.
- The scoped path is preserved, so the long-lived credential is still absent from every
  ordinary API request.
- The regression guard pins the `__Host-`-vs-`Path` compatibility that CI previously could not
  see; a future re-introduction of the broken combination fails the test, not the user.
- The asymmetry is now explicit and documented: `__Host-` where `Path=/` (access, CSRF), no
  prefix where the path is scoped (refresh).

### Negative

- The refresh cookie loses the browser-enforced `Path=/` and no-`Domain` fail-safes that
  `__Host-` provides. In practice nothing is lost: `build()` already sets `Secure` and never
  sets a `Domain` (the cookie is host-only by construction, not by browser enforcement), and
  the scoped path is intentional. The protection moves from "browser rejects misconfiguration"
  to "we configure it correctly", which the new test guards.
- Existing `__Host-bentley_rt` cookies in users' browsers from before this change are orphaned
  — they were never stored by browsers anyway (the bug), so there is nothing to migrate. A
  one-time re-login after deploy is the only user-visible effect, and affected sessions were
  already dying at 10-minute idle.
- BrowserKit still does not enforce `__Host-` rules, so the functional test suite remains a
  test double for this property. The new guard asserts the raw `Set-Cookie` header (which a
  browser would see), not the jar, so it catches the real-world invariant rather than the test
  double's leniency.

## Alternatives rejected and why

- **Keep `__Host-` on the refresh cookie and widen its path to `/`.** Rejected: it gives up
  the scoped path, so the long-lived refresh credential would be attached to every API
  request, increasing exposure and defeating a deliberate part of ADR-0002's design. The
  scoped path is the property worth keeping; the prefix is the one that has to give.

- **Move the refresh token to a `Bearer` Authorization header instead of a cookie.** Rejected
  for scope: the SPA is single-origin (the Vite proxy in dev, TLS-terminated same-origin in
  prod), and the cookie model is working for access and CSRF. A header-based refresh mode is
  the right answer for a genuinely separate SPA domain (already noted in ADR-0002 as the
  Bearer-header mode), but it is a larger redesign, not the fix for a cookie-name bug.

- **Derive all three cookie names from the unused `app.cookie_prefix` parameter.** Rejected
  for scope: it would make cookie identity a single explicit config point and retire the
  reliance on `bin/new-project`'s implicit text substitution, but it is a separate refactor
  (the constants become instance values, ~12 call sites change). It does not fix the bug; the
  bug is the `__Host-` + scoped-path combination. Bundling it would mix one decision with an
  unanalysed one. Raised here as a follow-up.

- **Catch the missing refresh cookie in the refresh controller and return a clearer error.**
  Rejected: it would not fix anything. The cookie was discarded by the client before the
  request reached the server; no server-side error handling can recover a cookie the browser
  refused to store. The fix is to make the cookie storable.

## Reversal cost

Cheap. Reversing is changing the `AuthCookies::REFRESH` constant back to `'__Host-bentley_rt'`
(and the ARCHITECTURE.md diagram line). The regression test then fails — the intended signal
that the broken combination is back. No schema change, no data migration, no API contract
change. The orphaned `__Host-bentley_rt` cookies (never stored by browsers) require no
cleanup.

## Implemented by

- `backend/src/Api/Security/AuthCookies.php` — `REFRESH` constant `'__Host-bentley_rt'` →
  `'bentley_rt'`; class docblock documents the `__Host-`-vs-scoped-path incompatibility.
- `backend/tests/Functional/Account/AuthenticationFlowTest.php` — asserts the access cookie is
  `__Host-`-prefixed and the refresh cookie is not (the regression guard), against the raw
  `Set-Cookie` header.
- `docs/ARCHITECTURE.md` — the login sequence diagram reflects the new refresh cookie name.