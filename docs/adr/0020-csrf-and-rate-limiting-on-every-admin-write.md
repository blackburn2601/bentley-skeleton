# 0020. CSRF double-submit on every unsafe `/api/v1` request, and rate limits on admin routes

- **Status:** accepted
- **Date:** 2026-08-23

## Context

`CsrfDoubleSubmitSubscriber` protected exactly three paths: `/api/v1/auth/refresh`,
`/api/v1/auth/logout` and `/api/v1/auth/logout-all`. Its docblock justified that narrowness:

> Applied only to the auth endpoints that act on the refresh cookie. Everything else
> authenticates with the access token, which a cross-site request cannot mint.

That reasoning does not survive contact with an admin API. The access token is
`__Host-bentley_at` — a cookie, at `Path=/`, that the browser attaches to cross-site requests
automatically. A cross-site request does not have to *mint* it; it only has to cause it to be
sent, which is the entire CSRF problem. The sentence was true of the endpoints that existed
when it was written, and false of every endpoint added since.

So before this ADR, an administrative write would have been defended by `SameSite=Strict`
alone — which the same docblock calls "a single attribute one careless change can weaken",
and which is precisely why the double-submit token exists as a second lock.

There is a second, more mundane problem: the allowlist is an exact-match `in_array`. It
cannot express `/api/v1/admin/users/{id}`. Any parameterised route is unprotectable by
construction, and nothing reports the gap.

`admin_write` (60/min per user) and `api_user` (token bucket, 120/min) were configured in
`rate_limiter.yaml`, wired into the limiter registry, and referenced by no controller.

## Decision

`isProtected()` becomes a prefix rule: **every unsafe method under `/api/v1/`**. The exact-path
allowlist is deleted rather than extended.

Admin endpoints declare `#[RateLimit('admin_write', keyedBy: 'user')]` on mutations and
`#[RateLimit('api_user', keyedBy: 'user')]` on reads.

## Consequences

### Positive

- Every current and future write under `/api/v1/` is covered without anyone remembering to add
  a path. A list that silently fails to cover new endpoints is worse than no list, because it
  looks like protection.
- Parameterised routes are protectable at all, which an exact-match list made impossible.
- Costs the SPA nothing: `client.ts` sets `X-CSRF-Token` unconditionally in `buildHeaders()`,
  for every request, already.
- Anonymous and machine clients are unaffected. `tokensMatch()` returns true when no CSRF
  cookie is present — only a *mismatch* is treated as an attack — so login, register and the
  Bearer-token mode behave exactly as before.
- Two rate-limit policies stop being decoration.

### Negative

- A client that holds a CSRF cookie and omits the header now gets a 403 on paths where it
  previously succeeded. For this SPA that set is empty, but any other client written against
  the old behaviour breaks.
- The prefix hard-codes `/api/v1/`. A future `/api/v2/` is unprotected until someone widens it
  — a smaller trap than the old one, but a trap.
- Rate limiting an admin's own bulk work is possible: 60 writes/minute is generous for a human
  and tight for a script driving the admin API.

## Alternatives rejected and why

- **Extend the allowlist with the admin paths** — cannot express route parameters, and
  reproduces the original failure: correct on the day it is written, quietly incomplete after
  the next endpoint.
- **Rely on `SameSite=Strict`** — it is the first lock and it is good, but it has had bypasses,
  is relaxed by some browsers for top-level navigations, and is one attribute away from being
  turned off by a config change nobody connects to CSRF.
- **A CSRF token in the body rather than a header** — would mean every request DTO carries a
  field that is not part of its domain, and `#[MapRequestPayload]` would validate it as data.
- **Per-route `#[Csrf]` attribute** — symmetrical with `#[RateLimit]`, but opt-in protection is
  the wrong default for a security control: forgetting the attribute is silent.

## Reversal cost

**Cheap.** One method in one subscriber, plus the attributes on the controllers.

## Implemented by

- `backend/src/Api/Listener/CsrfDoubleSubmitSubscriber.php`
- `backend/config/packages/rate_limiter.yaml`, `backend/src/Api/Attribute/RateLimit.php`
- `frontend/src/api/client.ts` (already sends the header on every request)
