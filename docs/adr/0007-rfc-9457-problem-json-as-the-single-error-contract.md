# 0007. RFC 9457 `application/problem+json` as the single error contract

- **Status:** accepted
- **Date:** 2026-08-22

## Context

A headless API needs one predictable error shape. Without it every client writes its own
guesswork: sometimes `{"error": "..."}`, sometimes `{"message": "..."}`, sometimes an HTML
error page from the framework.

## Decision

Every non-2xx response is `application/problem+json` per RFC 9457, with `type`, `title`,
`status`, `detail`, `instance`, plus `errors[]` for validation failures and `requestId` for
correlation. A single `ProblemJsonExceptionListener` performs the mapping. Services throw
domain exceptions and never know a status code (INV-08, INV-17).

## Consequences

### Positive

- Clients parse one shape. The SPA's fetch wrapper turns any error into a typed error in one
  place.
- `requestId` ties a user-visible failure to a log line and an audit row.
- Changing how an exception maps to a status is one file.

### Negative

- Domain exceptions must be expressive enough to map well, which is a little more design
  than throwing `BadRequestHttpException` in place.
- `problem+json` is unfamiliar to some clients, though it is just JSON.

## Alternatives rejected and why

- **Ad-hoc JSON per endpoint** — the situation this exists to prevent.
- **Symfony's default error responses** — leak framework detail in dev, are shapeless in
  prod, and have no validation-error structure.
- **JSON:API errors** — a reasonable standard, but it drags along the rest of JSON:API's
  document conventions, which we are not adopting.

## Reversal cost

**Cheap on the server, moderate for clients.** One listener produces every error body, but
changing the shape breaks every consumer.

## Implemented by

- `backend/src/Api/Listener/ProblemJsonExceptionListener.php`
- `frontend/src/api/client.ts`
