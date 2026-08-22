# 0002. JWT access token + rotating opaque refresh token, both in `__Host-` cookies

- **Status:** accepted
- **Date:** 2026-08-22

## Context

The SPA and the API share an origin. We need session continuity without asking the user to
log in every ten minutes, and we need a stolen token to be worth as little as possible.

## Decision

- **Access token:** RS256 JWT, 10 minutes, claims `sub`, `jti`, `roles`, `perm_v`. No
  permission list (ADR-0011).
- **Refresh token:** 256-bit opaque random value, 30 days, stored only as a hash. Rotated on
  every use; the old value is immediately invalid.
- **Reuse detection:** presenting an already-rotated refresh token revokes the entire token
  family and writes a `refresh_token_reuse` security event.
- Both tokens live in `__Host-` prefixed, `HttpOnly`, `Secure`, `SameSite=Strict` cookies
  with scoped paths. A double-submit CSRF token guards `refresh` and `logout`.
- A Bearer-header mode exists behind a config flag for machine clients.

## Consequences

### Positive

- JavaScript cannot read either token, so an XSS bug does not immediately become account
  takeover.
- Rotation plus reuse detection turns a stolen refresh token into a *detectable* event: the
  legitimate client's next refresh trips the alarm and kills the family.
- Short access tokens bound the damage window without a database read per request.

### Negative

- `__Host-` forbids a `Domain` attribute and requires `Path=/`, so the SPA and API must share
  an origin. A separate SPA domain requires the Bearer mode instead.
- Refresh is a database write on every use.
- Cookie auth needs CSRF defence, which header auth would not.

## Alternatives rejected and why

- **Access token in `localStorage`** — readable by any injected script. The convenience is
  not worth turning every XSS into a session theft.
- **Long-lived access token, no refresh** — revocation becomes impossible without a
  denylist, which reintroduces the per-request lookup that JWTs were chosen to avoid.
- **Non-rotating refresh token** — a stolen token then works silently for its full 30 days.
  Rotation is what makes theft observable.
- **Server-side sessions** — perfectly reasonable, but ties every request to shared session
  storage and complicates the machine-client story.

## Reversal cost

**Moderate.** Cookie transport is one authenticator and one listener. Dropping rotation is a
one-line change but discards reuse detection, which is the main security property here.

## Implemented by

- `backend/src/Account/` (token issuing, rotation, reuse detection)
- `backend/config/packages/security.yaml`, `backend/config/packages/lexik_jwt_authentication.yaml`
