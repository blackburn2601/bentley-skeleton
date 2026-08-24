# 0008. Rate-limit policy set, stored in Redis

- **Status:** partially superseded by 0024 — the `register`, `password_reset` and `verify_resend` policies below are removed (those endpoints no longer exist), and `login` is now keyed on IP **and username** rather than email. The remaining policies (login, refresh, admin_write) stand.
- **Date:** 2026-08-22

## Context

Authentication endpoints are the ones that get attacked, and they are the ones where a
missing limit is most expensive: credential stuffing, password-reset email flooding, and
enumeration all depend on being able to try repeatedly.

## Decision

A named policy per risk profile, applied with `#[RateLimit('login')]` and stored in Redis so
limits hold across container replicas:

| policy | limit |
|---|---|
| `login` | 5 / 15 min per (IP + email), sliding |
| `password_reset`, `verify_resend` | 3 / h per email, 10 / h per IP |
| `register` | 10 / h per IP |
| `refresh` | 30 / h per user |
| `api_user` | token bucket, 120 / min, burst 60, per user |
| `api_anon` | 30 / min per IP |
| `admin_write` | 60 / min per user |

Responses carry `X-RateLimit-{Limit,Remaining,Reset}`; a 429 is problem+json with
`Retry-After`.

## Consequences

### Positive

- Policies are named and listed in one config file and in `docs/ENDPOINTS.md`, so "what
  protects this endpoint?" has an answer.
- Keying `login` on IP *and* email frustrates both spraying one account and spraying many.

### Negative

- Redis becomes required for correct limiting; an in-memory fallback would silently
  under-limit across replicas.
- Limits tuned for a small app. They need load-testing before being trusted at scale, which
  `make new-project` says explicitly.
- Trusted-proxy configuration must be right or every client appears to share one IP.

## Alternatives rejected and why

- **Limiting at the edge only (CDN/WAF)** — good defence in depth, but not present in local
  development or CI, so the behaviour under test would differ from production.
- **Per-endpoint ad-hoc counters** — unnamed limits are undiscoverable and drift apart.
- **In-memory storage** — wrong the moment there is more than one container.

## Reversal cost

**Cheap.** Policies are configuration; the attribute and subscriber stay.

## Implemented by

- `backend/config/packages/rate_limiter.yaml`
- `backend/src/Platform/`, `backend/src/Api/Listener/RateLimitSubscriber.php`
