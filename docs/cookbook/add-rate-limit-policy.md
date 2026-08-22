# Recipe: add a rate-limit policy

## 1. Define it

`backend/config/packages/rate_limiter.yaml`. Policies are **named** so they are
discoverable — an unnamed inline limit is invisible to everyone who did not write it.

```yaml
framework:
    rate_limiter:
        note_export:
            policy: 'sliding_window'
            limit: 5
            interval: '1 hour'
```

Choosing a policy:

| Policy | Use when |
|---|---|
| `sliding_window` | Abuse protection where a burst at a window boundary matters — login, password reset |
| `token_bucket` | Normal API traffic that should tolerate short bursts — `api_user` |
| `fixed_window` | Rarely. Cheapest, but allows 2× the limit across a boundary |

## 2. Choose what to key on

This is the decision that determines whether the limit works.

- **Per user** — the default for authenticated endpoints.
- **Per IP** — for anonymous endpoints, but remember NAT: an office shares one address.
- **Per IP *and* per identifier** — what `login` does. Keyed on IP alone, an attacker
  rotates addresses; keyed on email alone, they spray many accounts from one host. Both
  together closes each gap.

Anything keyed on IP is wrong unless `TRUSTED_PROXIES` is configured correctly — behind a
load balancer every client otherwise appears to share the balancer's address, and one user
exhausts everyone's budget.

## 3. Apply it

```php
#[RateLimit('note_export')]
```

## 4. Verify

```bash
make check
make docs      # the policy appears in docs/ENDPOINTS.md; commit it
```

Test the limit, not just the happy path: exceed it and assert 429, a problem+json body and a
`Retry-After` header. A limit nobody has tripped in a test is a limit nobody knows works.

## Checklist

- [ ] Named policy in `rate_limiter.yaml`, not an inline limit
- [ ] Key chosen deliberately; composite key for anything credential-related
- [ ] Applied with `#[RateLimit]`
- [ ] Functional test asserts 429 + `Retry-After`
- [ ] `make docs` no diff
